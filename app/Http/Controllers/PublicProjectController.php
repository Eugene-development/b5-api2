<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class PublicProjectController extends Controller
{
    /**
     * Store a newly created project publicly using a user's secret key.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'secret_key' => [
                'required',
                'string',
                'size:26',
                'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/',
            ],
            'client_name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required', 
                'string', 
                'max:255',
                'regex:/^[\d\s\(\)\+\-]+$/',
                function ($attribute, $value, $fail) {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) < 10 || strlen($digits) > 11) {
                        $fail('Телефон должен содержать 10-11 цифр.');
                    }
                },
            ],
            'address' => ['nullable', 'string', 'max:500'],
            'comment' => ['nullable', 'string'],
        ]);

        $user = User::where('key', $validated['secret_key'])->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Пользователь с таким ключом не найден.'
            ], 404);
        }

        // Normalize phone to format 79991234567
        $phoneDigits = preg_replace('/\D/', '', $validated['phone']);
        if (str_starts_with($phoneDigits, '8') && strlen($phoneDigits) === 11) {
            $phoneDigits = '7' . substr($phoneDigits, 1);
        } else if (strlen($phoneDigits) === 10) {
            $phoneDigits = '7' . $phoneDigits;
        }

        // Generate unique contract number
        do {
            $contractNumber = 'LEAD-' . strtoupper(substr(uniqid(), -8));
        } while (Project::where('contract_number', $contractNumber)->exists());

        // Build comment with all client data
        $commentParts = [];
        $commentParts[] = "Телефон: {$phoneDigits}";
        if (!empty($validated['address'])) {
            $commentParts[] = "Адрес объекта: {$validated['address']}";
        }
        if (!empty($validated['comment'])) {
            $commentParts[] = "Комментарий: {$validated['comment']}";
        }
        $fullComment = implode("\n", $commentParts);

        $project = Project::create([
            'name' => $validated['client_name'] . ' - ' . $fullComment,
            'contract_number' => $contractNumber,
            'contract_date' => now()->toDateString(),
            'contract_amount' => 0,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Заявка успешно отправлена',
            'project' => $project,
        ], 201);
    }
}
