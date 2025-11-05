<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Producto;
use App\Models\DetalleVenta;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\VentaRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Mail\FacturaVenta;



class VentaController extends Controller
{
    /**
     * Procesar nueva venta
     * 
     * POST /api/ventas
     * Body: {
     *   "nombre_cliente": "Juan Pérez",
     *   "email_cliente": "juan@email.com", 
     *   "telefono_cliente": "1234567890",
     *   "productos": [
     *     {"id": 1, "cantidad": 2},
     *     {"id": 2, "cantidad": 1}
     *   ]
     * }
     */
    public function store(VentaRequest $request): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $datos = $request->validated();
            $productosVenta = $datos['productos'];
            $total = 0;
            
            // Verificar stock disponible para todos los productos
            $productosValidados = [];
            foreach ($productosVenta as $item) {
                $producto = Producto::findOrFail($item['id']);
                
                if (!$producto->activo) {
                    throw new \Exception("El producto '{$producto->nombre}' no está disponible");
                }
                
                if (!$producto->tieneStock($item['cantidad'])) {
                    throw new \Exception(
                        "Stock insuficiente para '{$producto->nombre}'. " .
                        "Disponible: {$producto->stock}, Solicitado: {$item['cantidad']}"
                    );
                }
                
                $subtotal = $producto->precio * $item['cantidad'];
                $total += $subtotal;
                
                $productosValidados[] = [
                    'producto' => $producto,
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $producto->precio,
                    'subtotal' => $subtotal
                ];
            }
            
            // Crear la venta
            $venta = Venta::create([
                'nombre_cliente' => $datos['nombre_cliente'],
                'email_cliente' => $datos['email_cliente'] ?? null,
                'telefono_cliente' => $datos['telefono_cliente'] ?? null,
                'total' => $total,
                'numero_factura' => Venta::generarNumeroFactura(),
                'id_usuario' => auth()->id(),
            ]);
            
            // Crear detalles de venta y actualizar stock
            foreach ($productosValidados as $item) {
                // Crear detalle
                DetalleVenta::create([
                    'id_venta' => $venta->id,
                    'id_producto' => $item['producto']->id,
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario'],
                    'subtotal' => $item['subtotal']
                ]);
                
                // Actualizar stock
                $item['producto']->reducirStock($item['cantidad']);
            }
            
            DB::commit();
            
            // Cargar relaciones para la respuesta
            $venta->load(['detalles.producto', 'usuario']);
            
            return response()->json([
                'success' => true,
                'message' => 'Venta procesada exitosamente',
                'data' => [
                    'venta' => $venta,
                    'numero_factura' => $venta->numero_factura,
                    'total' => $venta->total,
                    'productos_vendidos' => count($productosValidados),
                    'pdf_url' => route('ventas.pdf', $venta->id),
                ]
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la venta',
                'error' => $e->getMessage()
            ], 422);
        }
    }
    
    /**
     * Listar ventas con filtros
     * 
     * GET /api/ventas?fecha_inicio=2025-01-01&fecha_fin=2025-01-31&cliente=Juan&estado=completada
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Venta::with(['detalles.producto', 'usuario']);
            
            // Filtro por rango de fechas
            if ($request->filled('fecha_inicio')) {
                $query->whereDate('created_at', '>=', $request->fecha_inicio);
            }
            
            if ($request->filled('fecha_fin')) {
                $query->whereDate('created_at', '<=', $request->fecha_fin);
            }
            
            // Filtro por cliente
            if ($request->filled('cliente')) {
                $query->where('nombre_cliente', 'LIKE', '%' . $request->cliente . '%');
            }
            
            // Filtro por estado
            if ($request->filled('estado')) {
                $query->where('estado', $request->estado);
            }
            
            // Filtro por usuario/vendedor
            if ($request->filled('vendedor_id')) {
                $query->where('id_usuario', $request->vendedor_id);
            }
            
            // Ordenamiento
            $ordenPor = $request->get('orden_por', 'created_at');
            $direccion = $request->get('direccion', 'desc');
            
            $columnasValidas = ['created_at', 'total', 'nombre_cliente', 'estado'];
            if (in_array($ordenPor, $columnasValidas)) {
                $query->orderBy($ordenPor, $direccion);
            }
            
            // Paginación
            $porPagina = min($request->get('per_page', 15), 50);
            $ventas = $query->paginate($porPagina);
            
            // Estadísticas del período
            $estadisticas = [
                'total_ventas' => $ventas->total(),
                'monto_total' => $query->sum('total'),
                'venta_promedio' => $query->avg('total'),
                'ventas_hoy' => Venta::whereDate('created_at', today())->count(),
            ];
            
            return response()->json([
                'success' => true,
                'data' => [
                    'ventas' => $ventas->items(),
                    'paginacion' => [
                        'pagina_actual' => $ventas->currentPage(),
                        'por_pagina' => $ventas->perPage(),
                        'total' => $ventas->total(),
                        'total_paginas' => $ventas->lastPage(),
                    ],
                    'estadisticas' => $estadisticas,
                    'filtros_aplicados' => [
                        'fecha_inicio' => $request->fecha_inicio,
                        'fecha_fin' => $request->fecha_fin,
                        'cliente' => $request->cliente,
                        'estado' => $request->estado,
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener ventas',
                'error' => $e->getMessage()
            ], 500);
        }
    }
      /**
     * Obtener venta específica con todos sus detalles
     * 
     * GET /api/ventas/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $venta = Venta::with(['detalles.producto', 'usuario'])
                          ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'venta' => $venta,
                    'resumen' => [
                        'total_productos' => $venta->detalles->sum('cantidad'),
                        'tipos_productos' => $venta->detalles->count(),
                        'fecha_venta' => $venta->created_at->format('d/m/Y H:i'),
                        'vendedor' => $venta->usuario->nombre ?? 'Sistema',
                    ],
                    'acciones' => [
                        'pdf_url' => route('ventas.pdf', $venta->id),
                        'puede_enviar_email' => !empty($venta->email_cliente),
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Venta no encontrada',
                'error_code' => 'SALE_NOT_FOUND'
            ], 404);
        }
    }
    
    /**
     * Generar y descargar PDF de la factura
     * 
     * GET /api/ventas/{id}/pdf
     */
    public function generarPDF($id)
    {
        try {
            $venta = Venta::with(['detalles.producto', 'usuario'])->findOrFail($id);
            
            $pdf = PDF::loadView('facturas.venta', compact('venta'))
                     ->setPaper('a4', 'portrait');
            
            $nombreArchivo = "factura-{$venta->numero_factura}.pdf";
            
            return $pdf->download($nombreArchivo);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Enviar factura por email
     * 
     * POST /api/ventas/{id}/email
     */
   
    
    public function enviarEmail($id): JsonResponse
{
    try {
        $venta = Venta::with(['detalles.producto', 'usuario'])->findOrFail($id);
        
        if (empty($venta->email_cliente)) {
            return response()->json([
                'success' => false,
                'message' => 'La venta no tiene email registrado'
            ], 422);
        }
        
        // Enviar email
        Mail::to($venta->email_cliente)->send(new FacturaVenta($venta));
        
        return response()->json([
            'success' => true,
            'message' => "Factura enviada a {$venta->email_cliente}"
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al enviar email',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Cancelar venta (solo admin)
     * 
     * PATCH /api/ventas/{id}/cancelar
     */
    public function cancelar($id): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            $venta = Venta::with('detalles.producto')->findOrFail($id);
            
            if ($venta->estado === Venta::ESTADO_CANCELADA) {
                return response()->json([
                    'success' => false,
                    'message' => 'La venta ya está cancelada'
                ], 422);
            }
            
            // Restaurar stock de todos los productos
            foreach ($venta->detalles as $detalle) {
                $detalle->producto->increment('stock', $detalle->cantidad);
            }
            
            // Marcar venta como cancelada
            $venta->update(['estado' => Venta::ESTADO_CANCELADA]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Venta cancelada exitosamente. Stock restaurado.'
            ]);
            
        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Error al cancelar venta',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}