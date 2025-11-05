<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarRol
{
    /**
     * Manejar solicitud entrante
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        // Verificar que el usuario esté autenticado
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado',
                'error_code' => 'UNAUTHENTICATED'
            ], 401);
        }

        // Verificar que el usuario tenga uno de los roles requeridos
        $usuarioRol = $request->user()->rol;
        
        if (!in_array($usuarioRol, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para realizar esta acción',
                'error_code' => 'INSUFFICIENT_PERMISSIONS',
                'rol_requerido' => $roles,
                'rol_actual' => $usuarioRol
            ], 403);
        }

        return $next($request);
    }
}