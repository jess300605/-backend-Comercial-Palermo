<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    use HasFactory;

    protected $table = 'productos';

    protected $fillable = [
        'nombre',
        'descripcion',
        'precio',
        'categoria',
        'codigo_sku',
        'stock',
        'stock_minimo',
        'url_imagen',
        'activo',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'stock' => 'integer',
        'stock_minimo' => 'integer',
        'activo' => 'boolean',
    ];

    /**
     * Relación: Detalles de venta de este producto
     */
    public function detallesVenta()
    {
        return $this->hasMany(DetalleVenta::class, 'id_producto');
    }

    /**
     * Verificar si el producto tiene stock disponible
     */
    public function tieneStock(int $cantidad = 1): bool
    {
        return $this->stock >= $cantidad;
    }

    /**
     * Verificar si el stock está bajo el mínimo
     */
    public function stockBajo(): bool
    {
        return $this->stock <= $this->stock_minimo;
    }

    /**
     * Reducir stock del producto
     */
    public function reducirStock(int $cantidad): bool
    {
        if (!$this->tieneStock($cantidad)) {
            return false;
        }

        $this->decrement('stock', $cantidad);
        return true;
    }

    /**
     * Scope: Solo productos activos
     */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Scope: Productos con stock bajo
     */
    public function scopeStockBajo($query)
    {
        return $query->whereRaw('stock <= stock_minimo');
    }

    /**
     * Scope: Buscar por nombre o descripción
     */
    public function scopeBuscar($query, $termino)
    {
        return $query->where(function($q) use ($termino) {
            $q->where('nombre', 'LIKE', "%{$termino}%")
              ->orWhere('descripcion', 'LIKE', "%{$termino}%")
              ->orWhere('codigo_sku', 'LIKE', "%{$termino}%");
        });
    }

    /**
     * Scope: Filtrar por categoría
     */
    public function scopeDeCategoria($query, $categoria)
    {
        return $query->where('categoria', $categoria);
    }
}
