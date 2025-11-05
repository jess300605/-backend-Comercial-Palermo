<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductoRequest extends FormRequest
{
    /**
     * Determinar si el usuario está autorizado para esta request
     */
    public function authorize(): bool
    {
        // Verificar que el usuario tenga rol admin o empleado
        return $this->user() && $this->user()->esEmpleadoOAdmin();
    }

    /**
     * Reglas de validación
     */
    public function rules(): array
    {
        $productoId = $this->route('producto'); // Para updates
        
        return [
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:1000',
            'precio' => 'required|numeric|min:0.01|max:999999.99',
            'categoria' => 'required|string|max:100',
            'stock' => 'required|integer|min:0',
            'stock_minimo' => 'nullable|integer|min:0',
            'codigo_sku' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('productos')->ignore($productoId)
            ],
            'url_imagen' => 'nullable|url|max:500',
            'imagen' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048', // 2MB máximo
            'activo' => 'nullable|boolean',
        ];
    }

    /**
     * Mensajes de error personalizados
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre del producto es obligatorio',
            'nombre.max' => 'El nombre no puede exceder 255 caracteres',
            'precio.required' => 'El precio es obligatorio',
            'precio.min' => 'El precio debe ser mayor a 0',
            'precio.max' => 'El precio no puede exceder 999,999.99',
            'categoria_id.required' => 'La categoría es obligatoria',
            'categoria_id.integer' => 'El ID de la categoría debe ser un número entero',
            'categoria_id.exists' => 'La categoría seleccionada no existe',
            'stock.required' => 'El stock es obligatorio',
            'stock.min' => 'El stock no puede ser negativo',
            'codigo_sku.unique' => 'Este código SKU ya existe',
            'imagen.image' => 'El archivo debe ser una imagen',
            'imagen.mimes' => 'La imagen debe ser jpeg, png, jpg o webp',
            'imagen.max' => 'La imagen no puede exceder 2MB',
        ];
    }

    /**
     * Preparar datos para validación
     */
    protected function prepareForValidation(): void
    {
        // Limpiar y normalizar datos
        if ($this->has('nombre')) {
            $this->merge([
                'nombre' => trim($this->nombre)
            ]);
        }
        
        // ¡Ya no necesitas limpiar 'categoria'!
        
        // Establecer stock_minimo por defecto
        if (!$this->has('stock_minimo') || is_null($this->stock_minimo)) {
            $this->merge([
                'stock_minimo' => 5
            ]);
        }
    }
}