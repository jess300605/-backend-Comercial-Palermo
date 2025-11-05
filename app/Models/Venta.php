<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venta extends Model
{
    use HasFactory;

    protected $table = 'ventas';

    protected $fillable = [
        'nombre_cliente',
        'email_cliente',
        'telefono_cliente',
        'total',
        'estado',
        'numero_factura',
        'id_usuario',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Estados disponibles para las ventas
     */
    const ESTADO_PENDIENTE = 'pendiente';
    const ESTADO_COMPLETADA = 'completada';
    const ESTADO_CANCELADA = 'cancelada';

    /**
     * Relación: Usuario que realizó la venta
     */
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    /**
     * Relación: Detalles de la venta
     */
    public function detalles()
    {
        return $this->hasMany(DetalleVenta::class, 'id_venta');
    }

    /**
     * Generar número de factura único
     */
    public static function generarNumeroFactura(): string
    {
        $prefijo = 'FAC-';
        $numero = str_pad(self::count() + 1, 6, '0', STR_PAD_LEFT);
        $año = date('Y');
        
        return $prefijo . $año . '-' . $numero;
    }

    /**
     * Calcular total de la venta basado en los detalles
     */
    public function calcularTotal(): float
    {
        return $this->detalles->sum('subtotal');
    }

    /**
     * Scope: Ventas completadas
     */
    public function scopeCompletadas($query)
    {
        return $query->where('estado', self::ESTADO_COMPLETADA);
    }

    /**
     * Scope: Ventas en un rango de fechas
     */
    public function scopeEntreFechas($query, $fechaInicio, $fechaFin)
    {
        return $query->whereBetween('created_at', [$fechaInicio, $fechaFin]);
    }
}