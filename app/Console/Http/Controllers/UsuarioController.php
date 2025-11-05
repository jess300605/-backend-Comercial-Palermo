<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use Illuminate\validation\Rules\Password;
use Illuminate\Http\JsonResponse;

class UsuarioController extends Controller
{
    /**
     * Listar usuarios
     * GET /api/usuarios
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Usuario::query();
            
            // Filtro por rol
            if ($request->filled('rol')) {
                $query->where('rol', $request->rol);
            }
            
            // Filtro por estado
            if ($request->filled('activo')) {
                $activo = filter_var($request->activo, FILTER_VALIDATE_BOOLEAN);
                $query->where('activo', $activo);
            }
            
            // Búsqueda por nombre o email
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('nombre', 'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%");
                });
            }
            
            $porPagina = min($request->get('per_page', 15), 50);
            $usuarios = $query->orderBy('created_at', 'desc')->paginate($porPagina);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'usuarios' => $usuarios->items(),
                    'paginacion' => [
                        'pagina_actual' => $usuarios->currentPage(),
                        'por_pagina' => $usuarios->perPage(),
                        'total' => $usuarios->total(),
                        'total_paginas' => $usuarios->lastPage(),
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuarios',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Obtener un usuario específico
     * GET /api/usuarios/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $usuario = Usuario::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'usuario' => $usuario
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado',
                'error_code' => 'USER_NOT_FOUND'
            ], 404);
        }
    }
    
    /**
     * Crear nuevo usuario (registro)
     * POST /api/usuarios
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'nombre' => 'required|string|max:255',
                'email' => 'required|email|max:255|unique:usuarios,email',
                'contraseña' => ['required', 'string', 'min:8', 'confirmed'],
                'rol' => 'required|in:admin,empleado,usuario',
            ], [
                'nombre.required' => 'El nombre es obligatorio',
                'email.required' => 'El email es obligatorio',
                'email.email' => 'El email debe tener formato válido',
                'email.unique' => 'Este email ya está registrado',
                'contraseña.required' => 'La contraseña es obligatoria',
                'contraseña.min' => 'La contraseña debe tener al menos 8 caracteres',
                'contraseña.confirmed' => 'Las contraseñas no coinciden',
                'rol.required' => 'El rol es obligatorio',
                'rol.in' => 'El rol debe ser admin, empleado o usuario',
            ]);
            
            $usuario = Usuario::create([
                'nombre' => $request->nombre,
                'email' => $request->email,
                'contraseña' => $request->contraseña,
                'rol' => $request->rol,
                'activo' => true,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Usuario creado exitosamente',
                'data' => [
                    'usuario' => $usuario
                ]
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Actualizar usuario
     * PUT /api/usuarios/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $usuario = Usuario::findOrFail($id);
            
            $request->validate([
                'nombre' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|email|max:255|unique:usuarios,email,' . $id,
                'contraseña' => ['sometimes', 'required', 'string', 'min:8', 'confirmed'],
                'rol' => 'sometimes|required|in:admin,empleado,usuario',
                'activo' => 'sometimes|boolean',
            ]);
            
            $datos = [];
            
            if ($request->has('nombre')) {
                $datos['nombre'] = $request->nombre;
            }
            
            if ($request->has('email')) {
                $datos['email'] = $request->email;
            }
            
            if ($request->has('contraseña')) {
                $datos['contraseña'] = $request->contraseña;
            }
            
            if ($request->has('rol')) {
                $datos['rol'] = $request->rol;
            }
            
            if ($request->has('activo')) {
                $datos['activo'] = $request->activo;
            }
            
            $usuario->update($datos);
            
            return response()->json([
                'success' => true,
                'message' => 'Usuario actualizado exitosamente',
                'data' => [
                    'usuario' => $usuario->fresh()
                ]
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Eliminar (desactivar) usuario
     * DELETE /api/usuarios/{id}
     */
    public function destroy($id): JsonResponse
    {
        try {
            $usuario = Usuario::findOrFail($id);
            
            // No permitir eliminar el propio usuario
            if ($usuario->id === auth()->id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No puedes eliminar tu propio usuario',
                    'error_code' => 'CANNOT_DELETE_SELF'
                ], 422);
            }
            
            // Desactivar en lugar de eliminar
            $usuario->update(['activo' => false]);
            
            return response()->json([
                'success' => true,
                'message' => 'Usuario desactivado exitosamente'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Cambiar contraseña
     * POST /api/usuarios/{id}/cambiar-contraseña
     */
    public function cambiarContraseña(Request $request, $id): JsonResponse
    {
        try {
            $usuario = Usuario::findOrFail($id);
            
            $request->validate([
                'contraseña_actual' => 'required|string',
                'contraseña_nueva' => ['required', 'string', 'min:8', 'confirmed'],
            ], [
                'contraseña_actual.required' => 'La contraseña actual es obligatoria',
                'contraseña_nueva.required' => 'La contraseña nueva es obligatoria',
                'contraseña_nueva.min' => 'La contraseña debe tener al menos 8 caracteres',
                'contraseña_nueva.confirmed' => 'Las contraseñas no coinciden',
            ]);
            
            // Verificar contraseña actual
            if (!Hash::check($request->contraseña_actual, $usuario->contraseña)) {
                return response()->json([
                    'success' => false,
                    'message' => 'La contraseña actual es incorrecta',
                    'error_code' => 'INVALID_CURRENT_PASSWORD'
                ], 422);
            }
            
            $usuario->update([
                'contraseña' => $request->contraseña_nueva
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar contraseña',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
