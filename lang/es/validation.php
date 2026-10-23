<?php

/**
 * Spanish validation messages for the rules this API uses. Clients show these
 * texts verbatim, so they must read naturally for hemocentro staff.
 */
return [
    'after' => 'El campo :attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo :attribute debe ser una fecha igual o posterior a :date.',
    'array' => 'El campo :attribute debe ser una lista.',
    'before' => 'El campo :attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => 'El campo :attribute debe ser una fecha igual o anterior a :date.',
    'between' => [
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
        'array' => 'El campo :attribute debe tener entre :min y :max elementos.',
    ],
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'date_format' => 'El campo :attribute debe tener el formato :format.',
    'exists' => 'El :attribute seleccionado no existe.',
    'gt' => [
        'numeric' => 'El campo :attribute debe ser mayor que :value.',
        'string' => 'El campo :attribute debe tener más de :value caracteres.',
        'array' => 'El campo :attribute debe tener más de :value elementos.',
    ],
    'in' => 'El valor de :attribute no es válido.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'lt' => [
        'numeric' => 'El campo :attribute debe ser menor que :value.',
        'string' => 'El campo :attribute debe tener menos de :value caracteres.',
        'array' => 'El campo :attribute debe tener menos de :value elementos.',
    ],
    'max' => [
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
        'array' => 'El campo :attribute no puede tener más de :max elementos.',
    ],
    'min' => [
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
    ],
    'numeric' => 'El campo :attribute debe ser un número.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
    'required_with' => 'El campo :attribute es obligatorio cuando se indica :values.',
    'string' => 'El campo :attribute debe ser texto.',
    'unique' => 'Ya existe un registro con ese :attribute.',

    'custom' => [
        'dni' => [
            'regex' => 'El DNI debe tener 7 u 8 dígitos, sin puntos.',
            'unique' => 'Ya existe un paciente con ese DNI.',
        ],
        'fecha' => [
            'after_or_equal' => 'La fecha no puede ser anterior a :date.',
            'before_or_equal' => 'La fecha no puede ser posterior a :date.',
        ],
        'hasta' => [
            'after_or_equal' => 'La fecha hasta no puede ser anterior a la fecha desde.',
        ],
        'presion_diastolica' => [
            'lt' => 'La presión diastólica debe ser menor que la sistólica.',
        ],
    ],

    'attributes' => [
        'dni' => 'DNI',
        'telefono' => 'teléfono',
        'paciente_id' => 'paciente',
        'tipo_id' => 'tipo de donación',
        'sede_id' => 'sede',
        'motivo_id' => 'motivo',
        'hora' => 'hora',
        'hemoglobina' => 'hemoglobina',
        'hematocrito' => 'hematocrito',
        'plaquetas' => 'plaquetas',
        'presion_sistolica' => 'presión sistólica',
        'presion_diastolica' => 'presión diastólica',
        'frecuencia_cardiaca' => 'frecuencia cardíaca',
        'peso_donante' => 'peso del donante',
        'serie' => 'serie',
        'donacion' => 'donación',
        'serie_actual' => 'serie actual',
        'donacion_actual' => 'donación actual',
        'ciclo_actual' => 'ciclo actual',
        'username' => 'usuario',
        'password' => 'contraseña',
    ],
];
