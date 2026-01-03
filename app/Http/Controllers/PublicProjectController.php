<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectPhone;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Comment;
use App\Models\Client;
use App\Models\ClientPhone;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'client_id' => [
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
            'is_incognito' => ['nullable', 'boolean'],
        ]);

        $agent = User::where('key', $validated['secret_key'])->first();

        if (!$agent) {
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

        DB::beginTransaction();

        try {
            // Always create a new client (never search for existing)
            $client = Client::create([
                'id' => $validated['client_id'],
                'name' => $validated['client_name'],
            ]);

            // Create client phone
            ClientPhone::create([
                'client_id' => $client->id,
                'value' => $phoneDigits,
                'is_primary' => true,
            ]);

            // Get default project status
            $defaultStatus = ProjectStatus::getDefault();

            // Generate unique project value (4 letters + 4 digits)
            do {
                $letters = '';
                for ($i = 0; $i < 4; $i++) {
                    $letters .= chr(rand(65, 90)); // A-Z
                }
                $digits = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                $projectValue = 'PRO-' . $letters . '-' . $digits;
            } while (Project::where('value', $projectValue)->exists());

            // Create project with generated project value, client_id and agent ID
            $project = Project::create([
                'value' => $projectValue,
                'user_id' => $agent->id,
                'client_id' => $client->id,
                'status_id' => $defaultStatus?->id,
                'is_active' => true,
                'is_incognito' => $validated['is_incognito'] ?? false,
                'address' => $validated['address'],
            ]);

            // Create phone record for project
            ProjectPhone::create([
                'project_id' => $project->id,
                'value' => $phoneDigits,
                'contact_person' => $validated['client_name'],
                'is_primary' => true,
            ]);

            // Create comment if provided
            if (!empty($validated['comment'])) {
                $comment = Comment::create([
                    'value' => $validated['comment'],
                    'author_id' => $agent->id,
                    'author_name' => $agent->name,
                    'is_active' => true,
                    'is_approved' => true,
                    'approved_at' => now(),
                ]);

                // Link comment to project via commentables
                DB::table('commentables')->insert([
                    'comment_id' => $comment->id,
                    'commentable_id' => $project->id,
                    'commentable_type' => 'App\\Models\\Project',
                    'sort_order' => 0,
                    'is_pinned' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Заявка успешно отправлена',
                'project' => $project,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании заявки: ' . $e->getMessage(),
            ], 500);
        }
    }
}
