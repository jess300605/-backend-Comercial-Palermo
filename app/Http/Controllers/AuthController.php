<?php
namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Iniciar sesión de usuario
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        // Validar datos de entrada
        $request->validate([
            'email' => 'required|email',
            'contraseña' => 'required|string',
        ], [
            'email.required' => 'El email es obligatorio',
            'email.email' => 'El email debe tener formato válido',
            'contraseña.required' => 'La contraseña es obligatoria',
        ]);

        // Buscar usuario por email
        $usuario = Usuario::where('email', $request->email)
                          ->where('activo', true)
                          ->first();

        // Verificar credenciales
        if (!$usuario || !Hash::check($request->contraseña, $usuario->contraseña)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales incorrectas',
                'error_code' => 'INVALID_CREDENTIALS'
            ], 401);
        }

        // Generar token de acceso
        $token = $usuario->createToken('auth_token', 
            ['role:' . $usuario->rol], 
            now()->addHours(24)
        )->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión exitoso',
            'data' => [
                'usuario' => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->nombre,
                    'email' => $usuario->email,
                    'rol' => $usuario->rol,
                ],
                'token' => $token,
                'tipo_token' => 'Bearer',
                'expira_en' => 86400, // 24 horas en segundos
            ]
        ]);
    }

    /**
     * Cerrar sesión
     */
    public function logout(Request $request): JsonResponse
    {
        // Eliminar token actual
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada exitosamente'
        ]);
    }

    /**
     * Obtener información del usuario autenticado
     */
    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'usuario' => [
                    'id' => $usuario->id,
                    'nombre' => $usuario->nombre,
                    'email' => $usuario->email,
                    'rol' => $usuario->rol,
                    'activo' => $usuario->activo,
                ],
                'permisos' => $this->obtenerPermisos($usuario->rol),
                'token_valido_hasta' => $usuario->currentAccessToken()->expires_at,
            ]
        ]);
    }

    /**
     * Refrescar token de acceso
     */
    public function refresh(Request $request): JsonResponse
    {
        $usuario = $request->user();
        
        // Eliminar token actual
        $usuario->currentAccessToken()->delete();
        
        // Crear nuevo token
        $nuevoToken = $usuario->createToken('auth_token', 
            ['role:' . $usuario->rol], 
            now()->addHours(24)
        )->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Token renovado exitosamente',
            'data' => [
                'token' => $nuevoToken,
                'tipo_token' => 'Bearer',
                'expira_en' => 86400,
            ]
        ]);
    }

    /**
     * Obtener permisos según el rol
     */
    /**
 * Obtener permisos según el rol
 */
private function obtenerPermisos(string $rol): array
{
    $permisos = [
        'admin' => [
            'usuarios.crear', 'usuarios.editar', 'usuarios.eliminar', 'usuarios.ver',
            'productos.crear', 'productos.editar', 'productos.eliminar', 'productos.ver',
            'ventas.crear', 'ventas.ver', 'ventas.cancelar',
            'reportes.ventas', 'reportes.productos', 'reportes.usuarios',
            'dashboard.completo'
        ],
        'empleado' => [
            'productos.crear', 'productos.editar', 'productos.ver',
            'ventas.crear', 'ventas.ver',
            'reportes.ventas', 'reportes.productos',
            'dashboard.basico'
        ],
        'usuario' => [
            'productos.ver',
            'catalogo.buscar'
        ]
    ];

    return $permisos[$rol] ?? [];
}
}