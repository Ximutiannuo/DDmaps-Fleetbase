<?php

namespace Fleetbase\ConstructionSite\Support;

use Illuminate\Support\Facades\Validator;

final class RegistrationInput
{
    public static function plate(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($value)));
    }

    public static function phone(string $value): string
    {
        return preg_replace('/[\s().-]+/', '', trim($value));
    }

    public static function validate(array $data): array
    {
        foreach (['plate_number', 'driver_phone'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = $key === 'plate_number' ? self::plate($data[$key]) : self::phone($data[$key]);
            }
        }
        $rules = [
            'plate_number' => ['required', 'string', 'max:32', 'regex:/^[\pL\pN-]+$/u'],
            'driver_phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'driver_name' => ['required', 'string', 'max:100'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'vehicle_category' => ['nullable', 'in:earthwork,concrete,material_transport,machinery,management'],
        ];
        return Validator::make($data, $rules)->validate();
    }
}
