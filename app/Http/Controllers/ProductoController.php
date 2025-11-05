<?php
namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\ProductoRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ProductoController extends Controller
{
    /**
     * Listar productos con filtros y paginación
     * 
     * GET /api/productos?categoria=oficina&search=mesa&activo=1&page=1&per_page=15
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Construir query base
            $query = Producto::query();
            
            // Filtro por categoría
            if ($request->filled('categoria')) {
                $query->deCategoria($request->categoria);
            }
            
            // Búsqueda por nombre, descripción o SKU
            if ($request->filled('search')) {
                $query->buscar($request->search);
            }
            
            // Filtro por estado activo/inactivo
            if ($request->filled('activo')) {
                $activo = filter_var($request->activo, FILTER_VALIDATE_BOOLEAN);
                $query->where('activo', $activo);
            } else {
                // Por defecto solo productos activos
                $query->activos();
            }
            
            // Filtro por stock bajo
            if ($request->filled('stock_bajo') && $request->stock_bajo) {
                $query->stockBajo();
            }
            
            // Ordenamiento
            $ordenPor = $request->get('orden_por', 'nombre');
            $direccion = $request->get('direccion', 'asc');
            
            $columnasValidas = ['nombre', 'precio', 'stock', 'categoria', 'created_at'];
            if (in_array($ordenPor, $columnasValidas)) {
                $query->orderBy($ordenPor, $direccion);
            }
            
            // Paginación
            $porPagina = min($request->get('per_page', 15), 50); // Máximo 50 por página
            $productos = $query->paginate($porPagina);
            
            // Obtener categorías disponibles para filtros
            $categorias = Producto::activos()
                ->select('categoria')
                ->distinct()
                ->pluck('categoria')
                ->sort()
                ->values();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'productos' => $productos->items(),
                    'paginacion' => [
                        'pagina_actual' => $productos->currentPage(),
                        'por_pagina' => $productos->perPage(),
                        'total' => $productos->total(),
                        'total_paginas' => $productos->lastPage(),
                        'desde' => $productos->firstItem(),
                        'hasta' => $productos->lastItem(),
                    ],
                    'filtros' => [
                        'categorias_disponibles' => $categorias,
                        'filtros_aplicados' => [
                            'categoria' => $request->categoria,
                            'search' => $request->search,
                            'activo' => $request->activo,
                            'stock_bajo' => $request->boolean('stock_bajo'),
                        ]
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener productos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    
    /**
     * Obtener un producto específico
     * 
     * GET /api/productos/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $producto = Producto::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'producto' => $producto,
                    'estado_stock' => [
                        'disponible' => $producto->tieneStock(),
                        'stock_bajo' => $producto->stockBajo(),
                        'cantidad_disponible' => $producto->stock,
                        'stock_minimo' => $producto->stock_minimo,
                    ]
                ]
            ]);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado',
                'error_code' => 'PRODUCT_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Crear nuevo producto
     * 
     * POST /api/productos
     * Requiere rol: admin o empleado
     */
   
    public function store(ProductoRequest $request): JsonResponse
    {
        try {
            $datos = $request->validated();
            
            // Generar SKU automático si no se proporciona
            if (empty($datos['codigo_sku'])) {
                $datos['codigo_sku'] = $this->generarSKU($datos['nombre']);
            }
            
            // Manejar imagen si se proporciona
            if ($request->hasFile('imagen')) {
                $datos['url_imagen'] = $this->guardarImagen($request->file('imagen'));
            }
            
            $producto = Producto::create($datos);
            
            return response()->json([
                'success' => true,
                'message' => 'Producto creado exitosamente',
                'data' => [
                    'producto' => $producto,
                    'id' => $producto->id
                ]
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Actualizar producto existente
     * 
     * PUT /api/productos/{id}
     * Requiere rol: admin o empleado
     */
    public function update(ProductoRequest $request, $id): JsonResponse
    {
        try {
            $producto = Producto::findOrFail($id);
            $datos = $request->validated();
            
            // Manejar nueva imagen si se proporciona
            if ($request->hasFile('imagen')) {
                // Eliminar imagen anterior si existe
                if ($producto->url_imagen) {
                    $this->eliminarImagen($producto->url_imagen);
                }
                $datos['url_imagen'] = $this->guardarImagen($request->file('imagen'));
            }
            
            $producto->update($datos);
            
            return response()->json([
                'success' => true,
                'message' => 'Producto actualizado exitosamente',
                'data' => [
                    'producto' => $producto->fresh()
                ]
            ]);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado',
                'error_code' => 'PRODUCT_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Eliminar producto (soft delete)
     * 
     * DELETE /api/productos/{id}
     * Requiere rol: admin
     */
    public function destroy($id): JsonResponse
    {
        try {
            $producto = Producto::findOrFail($id);
            
            // Verificar que no tenga ventas asociadas
            if ($producto->detallesVenta()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar un producto que tiene ventas registradas',
                    'error_code' => 'PRODUCT_HAS_SALES'
                ], 422);
            }
            
            // Marcar como inactivo en lugar de eliminar
            $producto->update(['activo' => false]);
            
            return response()->json([
                'success' => true,
                'message' => 'Producto eliminado exitosamente'
            ]);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado',
                'error_code' => 'PRODUCT_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
 * Restaurar producto eliminado (activar)
 * 
 * PATCH /api/productos/{id}/restore
 * Requiere rol: admin
 */
public function restore($id): JsonResponse
{
    try {
        $producto = Producto::findOrFail($id);
        $producto->update(['activo' => true]);
        
        return response()->json([
            'success' => true,
            'message' => 'Producto restaurado exitosamente'
        ]);
        
    } catch (ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Producto no encontrado'
        ], 404);
    }
}
    
    /**
     * Actualizar stock de un producto
     * 
     * PATCH /api/productos/{id}/stock
     * Body: {"operacion": "add|subtract", "cantidad": 10}
     */
    public function actualizarStock(Request $request, $id): JsonResponse
    {
        $request->validate([
            'operacion' => 'required|in:add,subtract',
            'cantidad' => 'required|integer|min:1',
        ]);
        
        try {
            $producto = Producto::findOrFail($id);
            $cantidad = $request->cantidad;
            $operacion = $request->operacion;
            
            $stockAnterior = $producto->stock;
            
            if ($operacion === 'add') {
                $producto->increment('stock', $cantidad);
                $mensaje = "Stock aumentado en {$cantidad} unidades";
            } else {
                if ($producto->stock < $cantidad) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Stock insuficiente para la operación',
                        'data' => [
                            'stock_actual' => $producto->stock,
                            'cantidad_solicitada' => $cantidad
                        ]
                    ], 422);
                }
                
                $producto->decrement('stock', $cantidad);
                $mensaje = "Stock reducido en {$cantidad} unidades";
            }
            
            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data' => [
                    'stock_anterior' => $stockAnterior,
                    'stock_actual' => $producto->fresh()->stock,
                    'stock_bajo' => $producto->fresh()->stockBajo()
                ]
            ]);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado',
                'error_code' => 'PRODUCT_NOT_FOUND'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Generar SKU único automáticamente
     */
    private function generarSKU(string $nombre): string
    {
        // Tomar primeras letras del nombre
        $prefijo = strtoupper(substr(str_replace(' ', '', $nombre), 0, 3));
        
        // Agregar timestamp para unicidad
        $sufijo = substr(time(), -6);
        
        return $prefijo . '-' . $sufijo;
    }
    
    /**
     * Guardar imagen de producto
     */
    private function guardarImagen($imagen): string
    {
        $path = $imagen->store('productos', 'public');
        return Storage::url($path);
    }
    
    /**
     * Eliminar imagen de producto
     */
    private function eliminarImagen(string $url): void
    {
        $path = str_replace('/storage/', '', parse_url($url, PHP_URL_PATH));
        Storage::disk('public')->delete($path);
    }
    public function __construct()
{
    // Solo aplicar auth a ciertos métodos, no a index y show
    $this->middleware('auth:sanctum')->except(['index', 'show']);
}
    
}

