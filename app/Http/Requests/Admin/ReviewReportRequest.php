<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReportStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Resolver o descartar un reporte (T-37, RF-15). Siempre con motivo, para la bitácora. Solo un reporte
 * resuelto puede suspender al reportado en el mismo paso.
 */
class ReviewReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([ReportStatus::Reviewed->value, ReportStatus::Dismissed->value])],
            'reason' => ['required', 'string', 'max:1000'],
            'suspend' => ['sometimes', 'boolean', 'declined_if:status,'.ReportStatus::Dismissed->value],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'El estado debe ser reviewed (resuelto) o dismissed (descartado).',
            'suspend.declined_if' => 'Un reporte descartado no puede suspender la cuenta.',
        ];
    }

    public function decision(): ReportStatus
    {
        return ReportStatus::from($this->string('status')->toString());
    }
}
