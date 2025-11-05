<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\DetalleVenta;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReporteController extends Controller
{

    public function dashboard(): JsonResponse
{
    try {
        $hoy = Carbon::today();
        $mesActual = Carbon::now()->startOfMonth();
        
        // Test cada consulta por separado
        $metrics = [];
        
        // 1. Ventas hoy
        $metrics['ventas_hoy'] = Venta::whereDate('created_at', $hoy)
                                    ->where('estado', 'completada')
                                    ->count();
        
        // 2. Monto hoy
        $metrics['monto_hoy'] = floatval(Venta::whereDate('created_at', $hoy)
                                            ->where('estado', 'completada')
                                            ->sum('total'));
        
        // 3. Productos activos
        $metrics['productos_activos'] = Producto::where('activo', true)->count();
        
        // 4. Productos stock bajo (sin scope)
        $metrics['productos_stock_bajo'] = Producto::where('activo', true)
                                                 ->whereRaw('stock <= stock_minimo')
                                                 ->count();
        
        return response()->json([
            'success' => true,
            'data' => [
                'metricas_principales' => $metrics,
                'fecha_actualizacion' => now()->format('Y-m-d H:i:s')
            ]
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al cargar dashboard',
            'error' => $e->getMessage(),
            'trace' => env('APP_DEBUG') ? $e->getTraceAsString() : null
        ], 500);
    }
}   
    
    /**
     * Reportes de ventas con filtros avanzados
     * 
     * GET /api/reportes/ventas?fecha_inicio=2025-01-01&fecha_fin=2025-01-31&agrupar_por=dia
     */
    public function reporteVentas(Request $request): JsonResponse
    {
        try {
            $fechaInicio = $request->get('fecha_inicio', Carbon::now()->startOfMonth()->format('Y-m-d'));
            $fechaFin = $request->get('fecha_fin', Carbon::now()->endOfMonth()->format('Y-m-d'));
            $agruparPor = $request->get('agrupar_por', 'dia'); // dia, semana, mes
            
            $query = Venta::where('estado', 'completada')
                         ->whereBetween('created_at', [$fechaInicio, $fechaFin]);
            
            // Totales del período
            $totalVentas = $query->count();
            $montoTotal = $query->sum('total');
            $promedioVenta = $totalVentas > 0 ? $montoTotal / $totalVentas : 0;
            
            // Agrupación de datos
            $datosGrafico = [];
            
            if ($agruparPor === 'dia') {
                $ventas = DB::table('ventas')
                           ->select(DB::raw('DATE(created_at) as fecha'), 
                                  DB::raw('COUNT(*) as cantidad'), 
                                  DB::raw('SUM(total) as monto'))
                           ->where('estado', 'completada')
                           ->whereBetween('created_at', [$fechaInicio, $fechaFin])
                           ->groupBy('fecha')
                           ->orderBy('fecha')
                           ->get();
                
                foreach ($ventas as $venta) {
                    $datosGrafico[] = [
                        'periodo' => Carbon::parse($venta->fecha)->format('d/m'),
                        'fecha' => $venta->fecha,
                        'cantidad' => $venta->cantidad,
                        'monto' => floatval($venta->monto)
                    ];
                }
            }
            
            // Top 10 productos del período
            $topProductos = DetalleVenta::select('id_producto',
                                               DB::raw('SUM(cantidad) as total_vendido'),
                                               DB::raw('SUM(subtotal) as ingresos'))
                                       ->whereHas('venta', function($query) use ($fechaInicio, $fechaFin) {
                                           $query->where('estado', 'completada')
                                                 ->whereBetween('created_at', [$fechaInicio, $fechaFin]);
                                       })
                                       ->with('producto:id,nombre,precio')
                                       ->groupBy('id_producto')
                                       ->orderBy('ingresos', 'desc')
                                       ->limit(10)
                                       ->get();
            
            // Comparación con período anterior
            $diasPeriodo = Carbon::parse($fechaInicio)->diffInDays(Carbon::parse($fechaFin)) + 1;
            $fechaInicioAnterior = Carbon::parse($fechaInicio)->subDays($diasPeriodo)->format('Y-m-d');
            $fechaFinAnterior = Carbon::parse($fechaInicio)->subDay()->format('Y-m-d');
            
            $ventasAnterior = Venta::where('estado', 'completada')
                                  ->whereBetween('created_at', [$fechaInicioAnterior, $fechaFinAnterior])
                                  ->count();
            
            $montoAnterior = Venta::where('estado', 'completada')
                                 ->whereBetween('created_at', [$fechaInicioAnterior, $fechaFinAnterior])
                                 ->sum('total');
            
            $crecimientoVentas = $ventasAnterior > 0 ? 
                (($totalVentas - $ventasAnterior) / $ventasAnterior) * 100 : 0;
            
            $crecimientoMonto = $montoAnterior > 0 ? 
                (($montoTotal - $montoAnterior) / $montoAnterior) * 100 : 0;
            
            return response()->json([
                'success' => true,
                'data' => [
                    'periodo' => [
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $fechaFin,
                        'dias' => $diasPeriodo
                    ],
                    'resumen' => [
                        'total_ventas' => $totalVentas,
                        'monto_total' => floatval($montoTotal),
                        'promedio_venta' => floatval($promedioVenta),
                        'crecimiento_ventas' => floatval($crecimientoVentas),
                        'crecimiento_monto' => floatval($crecimientoMonto)
                    ],
                    'grafico_ventas' => $datosGrafico,
                    'top_productos' => $topProductos
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar reporte de ventas',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Productos más vendidos
     * 
     * GET /api/reportes/productos-populares?limite=20&periodo=mes
     */
    public function productosPopulares(Request $request): JsonResponse
    {
        try {
            $limite = min($request->get('limite', 10), 50);
            $periodo = $request->get('periodo', 'mes'); // semana, mes, trimestre, año
            
         switch($periodo) {
    case 'semana':
        $fechaInicio = Carbon::now()->startOfWeek();
        break;
    case 'mes':
        $fechaInicio = Carbon::now()->startOfMonth();
        break;
    case 'trimestre':
        $fechaInicio = Carbon::now()->startOfQuarter();
        break;
    case 'año':
        $fechaInicio = Carbon::now()->startOfYear();
        break;
    default:
        $fechaInicio = Carbon::now()->startOfMonth();
        break;
}
            $productos = DetalleVenta::select('id_producto',
                                           DB::raw('SUM(cantidad) as total_vendido'),
                                           DB::raw('COUNT(DISTINCT id_venta) as veces_comprado'),
                                           DB::raw('SUM(subtotal) as ingresos_generados'),
                                           DB::raw('AVG(precio_unitario) as precio_promedio'))
                                   ->whereHas('venta', function($query) use ($fechaInicio) {
                                       $query->where('estado', 'completada')
                                             ->where('created_at', '>=', $fechaInicio);
                                   })
                                   ->with(['producto' => function($query) {
                                       $query->select('id', 'nombre', 'categoria', 'stock', 'precio');
                                   }])
                                   ->groupBy('id_producto')
                                   ->orderBy('ingresos_generados', 'desc')
                                   ->limit($limite)
                                   ->get();
            
            // Calcular porcentajes
            $totalIngresos = $productos->sum('ingresos_generados');
            $totalCantidad = $productos->sum('total_vendido');
            
            $productosConPorcentaje = $productos->map(function($item) use ($totalIngresos, $totalCantidad) {
                return [
                    'producto' => $item->producto,
                    'estadisticas' => [
                        'total_vendido' => $item->total_vendido,
                        'veces_comprado' => $item->veces_comprado,
                        'ingresos_generados' => floatval($item->ingresos_generados),
                        'precio_promedio' => floatval($item->precio_promedio),
                        'porcentaje_ingresos' => $totalIngresos > 0 ? 
                            floatval(($item->ingresos_generados / $totalIngresos) * 100) : 0,
                        'porcentaje_cantidad' => $totalCantidad > 0 ? 
                            floatval(($item->total_vendido / $totalCantidad) * 100) : 0
                    ]
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => [
                    'periodo' => [
                        'tipo' => $periodo,
                        'fecha_inicio' => $fechaInicio->format('Y-m-d'),
                        'fecha_fin' => now()->format('Y-m-d')
                    ],
                    'productos' => $productosConPorcentaje,
                    'totales' => [
                        'productos_analizados' => $productos->count(),
                        'ingresos_totales' => floatval($totalIngresos),
                        'unidades_totales' => $totalCantidad
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener productos populares',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Reporte de productos con stock bajo
     * 
     * GET /api/reportes/stock-bajo?umbral=5
     */
    public function stockBajo(Request $request): JsonResponse
    {
        try {
            $umbral = $request->get('umbral', null);
            
            $query = Producto::where('activo', true);
            
            if ($umbral) {
                $query->where('stock', '<=', $umbral);
            } else {
                $query->whereRaw('stock <= stock_minimo');
            }
            
            $productos = $query->orderBy('stock', 'asc')
                             ->get()
                             ->map(function($producto) {
                                 return [
                                     'id' => $producto->id,
                                     'nombre' => $producto->nombre,
                                     'categoria' => $producto->categoria,
                                     'stock_actual' => $producto->stock,
                                     'stock_minimo' => $producto->stock_minimo,
                                     'diferencia' => $producto->stock - $producto->stock_minimo,
                                     'precio' => floatval($producto->precio),
                                     'valor_inventario' => floatval($producto->precio * $producto->stock),
                                     'estado' => $producto->stock == 0 ? 'sin_stock' : 
                                               ($producto->stock <= $producto->stock_minimo ? 'critico' : 'bajo')
                                 ];
                             });
            
            // Estadísticas
            $sinStock = $productos->where('stock_actual', 0)->count();
            $stockCritico = $productos->where('estado', 'critico')->count();
            $valorTotalAfectado = $productos->sum('valor_inventario');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'productos' => $productos->values(),
                    'estadisticas' => [
                        'total_productos_afectados' => $productos->count(),
                        'sin_stock' => $sinStock,
                        'stock_critico' => $stockCritico,
                        'valor_inventario_afectado' => floatval($valorTotalAfectado)
                    ],
                    'recomendaciones' => [
                        'reabastecer_prioritario' => $productos->where('estado', 'sin_stock')->count(),
                        'revisar_stock_minimo' => $productos->where('diferencia', '<', -5)->count()
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener reporte de stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Reporte financiero consolidado
     * 
     * GET /api/reportes/financiero?periodo=mes
     */
    public function reporteFinanciero(Request $request): JsonResponse
    {
        try {
            $periodo = $request->get('periodo', 'mes');
           switch($periodo) {
    case 'semana':
        $fechaInicio = Carbon::now()->startOfWeek();
        break;
    case 'mes':
        $fechaInicio = Carbon::now()->startOfMonth();
        break;
    case 'trimestre':
        $fechaInicio = Carbon::now()->startOfQuarter();
        break;
    case 'año':
        $fechaInicio = Carbon::now()->startOfYear();
        break;
    default:
        $fechaInicio = Carbon::now()->startOfMonth();
        break;
}
            // Ingresos por ventas
            $ingresosBrutos = Venta::where('estado', 'completada')
                                  ->where('created_at', '>=', $fechaInicio)
                                  ->sum('total');
            
            $totalVentas = Venta::where('estado', 'completada')
                               ->where('created_at', '>=', $fechaInicio)
                               ->count();
            
            // Valor del inventario actual
            $valorInventario = Producto::where('activo', true)
                                     ->selectRaw('SUM(precio * stock) as valor_total')
                                     ->value('valor_total') ?? 0;
            
            // Productos vendidos por categoría
            $ventasPorCategoria = DB::table('detalles_venta')
                                   ->join('productos', 'detalles_venta.id_producto', '=', 'productos.id')
                                   ->join('ventas', 'detalles_venta.id_venta', '=', 'ventas.id')
                                   ->where('ventas.estado', 'completada')
                                   ->where('ventas.created_at', '>=', $fechaInicio)
                                   ->select('productos.categoria',
                                          DB::raw('SUM(detalles_venta.subtotal) as ingresos'),
                                          DB::raw('SUM(detalles_venta.cantidad) as cantidad'))
                                   ->groupBy('productos.categoria')
                                   ->orderBy('ingresos', 'desc')
                                   ->get();
            
            // Tendencia de ventas (últimos 30 días)
            $tendenciaVentas = DB::table('ventas')
                                ->select(DB::raw('DATE(created_at) as fecha'),
                                       DB::raw('SUM(total) as ingresos_dia'))
                                ->where('estado', 'completada')
                                ->where('created_at', '>=', Carbon::now()->subDays(30))
                                ->groupBy('fecha')
                                ->orderBy('fecha')
                                ->get()
                                ->map(function($item) {
                                    return [
                                        'fecha' => $item->fecha,
                                        'ingresos' => floatval($item->ingresos_dia)
                                    ];
                                });
            
            return response()->json([
                'success' => true,
                'data' => [
                    'periodo' => [
                        'tipo' => $periodo,
                        'fecha_inicio' => $fechaInicio->format('Y-m-d'),
                        'fecha_fin' => now()->format('Y-m-d')
                    ],
                    'resumen_financiero' => [
                        'ingresos_brutos' => floatval($ingresosBrutos),
                        'total_ventas' => $totalVentas,
                        'ticket_promedio' => $totalVentas > 0 ? floatval($ingresosBrutos / $totalVentas) : 0,
                        'valor_inventario_actual' => floatval($valorInventario)
                    ],
                    'ventas_por_categoria' => $ventasPorCategoria,
                    'tendencia_ventas' => $tendenciaVentas
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar reporte financiero',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Rendimiento por vendedores
     * 
     * GET /api/reportes/vendedores?periodo=mes
     */
    public function reporteVendedores(Request $request): JsonResponse
    {
        try {
            $periodo = $request->get('periodo', 'mes');
            
         switch($periodo) {
    case 'semana':
        $fechaInicio = Carbon::now()->startOfWeek();
        break;
    case 'mes':
        $fechaInicio = Carbon::now()->startOfMonth();
        break;
    case 'trimestre':
        $fechaInicio = Carbon::now()->startOfQuarter();
        break;
    case 'año':
        $fechaInicio = Carbon::now()->startOfYear();
        break;
    default:
        $fechaInicio = Carbon::now()->startOfMonth();
        break;
}
            
            $vendedores = DB::table('ventas')
                           ->join('usuarios', 'ventas.id_usuario', '=', 'usuarios.id')
                           ->where('ventas.estado', 'completada')
                           ->where('ventas.created_at', '>=', $fechaInicio)
                           ->select('usuarios.id',
                                  'usuarios.nombre',
                                  'usuarios.rol',
                                  DB::raw('COUNT(ventas.id) as total_ventas'),
                                  DB::raw('SUM(ventas.total) as ingresos_generados'),
                                  DB::raw('AVG(ventas.total) as ticket_promedio'))
                           ->groupBy('usuarios.id', 'usuarios.nombre', 'usuarios.rol')
                           ->orderBy('ingresos_generados', 'desc')
                           ->get();
            
            $totalIngresos = $vendedores->sum('ingresos_generados');
            $totalVentas = $vendedores->sum('total_ventas');
            
            $vendedoresConPorcentaje = $vendedores->map(function($vendedor) use ($totalIngresos, $totalVentas) {
                return [
                    'vendedor' => [
                        'id' => $vendedor->id,
                        'nombre' => $vendedor->nombre,
                        'rol' => $vendedor->rol
                    ],
                    'estadisticas' => [
                        'total_ventas' => $vendedor->total_ventas,
                        'ingresos_generados' => floatval($vendedor->ingresos_generados),
                        'ticket_promedio' => floatval($vendedor->ticket_promedio),
                        'porcentaje_ingresos' => $totalIngresos > 0 ? 
                            floatval(($vendedor->ingresos_generados / $totalIngresos) * 100) : 0,
                        'porcentaje_ventas' => $totalVentas > 0 ? 
                            floatval(($vendedor->total_ventas / $totalVentas) * 100) : 0
                    ]
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => [
                    'periodo' => [
                        'tipo' => $periodo,
                        'fecha_inicio' => $fechaInicio->format('Y-m-d'),
                        'fecha_fin' => now()->format('Y-m-d')
                    ],
                    'vendedores' => $vendedoresConPorcentaje,
                    'totales' => [
                        'vendedores_activos' => $vendedores->count(),
                        'ingresos_totales' => floatval($totalIngresos),
                        'ventas_totales' => $totalVentas
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar reporte de vendedores',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
