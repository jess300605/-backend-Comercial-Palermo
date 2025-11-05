<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->esEmpleadoOAdmin();
    }

    public function rules(): array
    {
        return [
            'nombre_cliente' => 'required|string|max:255',
            'email_cliente' => 'nullable|email|max:255',
            'telefono_cliente' => 'nullable|string|max:20',
            
            'productos' => 'required|array|min:1',
            'productos.*.id' => 'required|exists:productos,id',
            'productos.*.cantidad' => 'required|integer|min:1|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'nombre_cliente.required' => 'El nombre del cliente es obligatorio',
            'email_cliente.email' => 'El email del cliente debe tener formato válido',
            
            'productos.required' => 'Debe incluir al menos un producto',
            'productos.*.id.required' => 'El ID del producto es obligatorio',
            'productos.*.id.exists' => 'El producto seleccionado no existe',
            'productos.*.cantidad.required' => 'La cantidad es obligatoria',
            'productos.*.cantidad.min' => 'La cantidad mínima es 1',
            'productos.*.cantidad.max' => 'La cantidad máxima es 1000',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Limpiar datos del cliente
        if ($this->has('nombre_cliente')) {
            $this->merge([
                'nombre_cliente' => trim($this->nombre_cliente)
            ]);
        }
        
        if ($this->has('telefono_cliente')) {
            $this->merge([
                'telefono_cliente' => preg_replace('/[^0-9]/', '', $this->telefono_cliente)
            ]);
        }
    }
}
