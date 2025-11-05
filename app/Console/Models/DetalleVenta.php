<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetalleVenta extends Model
{
    use HasFactory;

    protected $table = 'detalles_venta';
    
    // No usar timestamps automáticos, solo creado_en
    public $timestamps = false;
    protected $dates = ['creado_en'];

    protected $fillable = [
        'id_venta',
        'id_producto',
        'cantidad',
        'precio_unitario',
        'subtotal',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'precio_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'creado_en' => 'datetime',
    ];

    /**
     * Relación: Venta a la que pertenece este detalle
     */
    public function venta()
    {
        return $this->belongsTo(Venta::class, 'id_venta');
    }

    /**
     * Relación: Producto vendido
     */
    public function producto()
    {
        return $this->belongsTo(Producto::class, 'id_producto');
    }

    /**
     * Calcular subtotal automáticamente
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($detalle) {
            $detalle->subtotal = $detalle->cantidad * $detalle->precio_unitario;
            $detalle->creado_en = now();
        });
    }
}