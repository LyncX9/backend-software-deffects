<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scan extends Model
{
    protected $fillable = [
        'filename',
        'file_path',
        'metrics',
        'result',
        'status',
        'defect_probability',
        'risk_level',
    ];

    protected $casts = [
        'metrics' => 'array',
        'result' => 'array',
        'defect_probability' => 'float',
    ];

    public function getRiskLevelAttribute($value)
    {
        if (!$value && $this->defect_probability !== null) {
            if ($this->defect_probability >= 70) {
                return 'high';
            } elseif ($this->defect_probability >= 30) {
                return 'medium';
            }
            return 'low';
        }
        return $value;
    }
}
