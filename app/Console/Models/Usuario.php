<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Hash; // ← Agregar esta importación

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Tabla asociada al modelo
     */
    protected $table = 'usuarios';

    /**
     * Atributos que se pueden asignar masivamente
     */
    protected $fillable = [
        'nombre',
        'email',
        'contraseña',
        'rol',
        'activo',
    ];

    /**
     * Atributos ocultos para serialización
     */
    protected $hidden = [
        'contraseña',
        'remember_token',
    ];

    /**
     * Atributos que deben ser convertidos a tipos nativos
     */
    protected $casts = [
        'email_verificado_en' => 'datetime',
        'activo' => 'boolean',
        // 'contraseña' => 'hashed', // ← QUITAR esto (no existe el cast 'hashed')
    ];

    /**
     * CONSTANTES IMPORTANTES para la autenticación de Laravel
     */
    public const ROL_ADMIN = 'admin';
    public const ROL_EMPLEADO = 'empleado';
    public const ROL_USUARIO = 'usuario';

    /**
     * Get the name of the unique identifier for the user.
     * Laravel por defecto usa 'id', pero por si acaso lo definimos
     */
    public function getAuthIdentifierName()
    {
        return 'id';
    }

    /**
     * Get the unique identifier for the user.
     */
    public function getAuthIdentifier()
    {
        return $this->id;
    }

    /**
     * Get the password for the user.
     * ¡IMPORTANTE! Aquí le decimos a Laravel que nuestro campo se llama 'contraseña'
     */
    public function getAuthPassword()
    {
        return $this->contraseña;
    }

    /**
     * Get the remember token for the user.
     */
    public function getRememberToken()
    {
        return $this->remember_token;
    }

    /**
     * Set the remember token for the user.
     */
    public function setRememberToken($value)
    {
        $this->remember_token = $value;
    }

    /**
     * Get the remember token name for the user.
     */
    public function getRememberTokenName()
    {
        return 'remember_token';
    }

    /**
     * Mutator: Hashear automáticamente la contraseña al asignarla
     */
    public function setContraseñaAttribute($value)
    {
        $this->attributes['contraseña'] = Hash::make($value);
    }

    /**
     * Verificar si el usuario es administrador
     */
    public function esAdmin(): bool
    {
        return $this->rol === self::ROL_ADMIN;
    }

    /**
     * Verificar si el usuario es empleado o admin
     */
    public function esEmpleadoOAdmin(): bool
    {
        return in_array($this->rol, [self::ROL_EMPLEADO, self::ROL_ADMIN]);
    }

    /**
     * Relación: Ventas realizadas por este usuario
     */
    public function ventas()
    {
        return $this->hasMany(Venta::class, 'id_usuario');
    }

    /**
     * Scope: Solo usuarios activos
     */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Scope: Filtrar por rol
     */
    public function scopeConRol($query, $rol)
    {
        return $query->where('rol', $rol);
    }

    /**
     * Find the user instance for the given username.
     * Necesario para autenticación personalizada
     */
    public function findForPassport($username)
    {
        return $this->where('email', $username)->first();
    }
}