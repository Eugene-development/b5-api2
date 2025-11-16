<?php

namespace App\GraphQL\Mutations;

use App\Models\Client;
use App\Models\ClientPhone;
use Illuminate\Support\Facades\DB;

final class UpdateClientWithPhones
{
    /**
     * Update a client with their phone numbers.
     */
    public function __invoke($_, array $args)
    {
        return DB::transaction(function () use ($args) {
            $input = $args['input'];

            // Find the client
            $client = Client::findOrFail($input['id']);

            // Update client basic information
            $clientData = [];
            if (isset($input['name'])) {
                $clientData['name'] = $input['name'];
            }
            if (isset($input['birthday'])) {
                $clientData['birthday'] = $input['birthday'];
            }
            if (isset($input['ban'])) {
                $clientData['ban'] = $input['ban'];
            }
            if (isset($input['status_id'])) {
                $clientData['status_id'] = $input['status_id'];
            }

            if (!empty($clientData)) {
                $client->update($clientData);
            }

            // Update phones if provided
            if (isset($input['phones'])) {
                // Get existing phone IDs
                $existingPhoneIds = $client->phones()->pluck('id')->toArray();
                $updatedPhoneIds = [];

                foreach ($input['phones'] as $phoneData) {
                    if (isset($phoneData['id']) && in_array($phoneData['id'], $existingPhoneIds)) {
                        // Update existing phone
                        $phone = ClientPhone::find($phoneData['id']);
                        $phone->update([
                            'value' => $phoneData['value'],
                            'is_primary' => $phoneData['is_primary'] ?? false,
                        ]);
                        $updatedPhoneIds[] = $phoneData['id'];
                    } else {
                        // Create new phone
                        $phone = ClientPhone::create([
                            'client_id' => $client->id,
                            'value' => $phoneData['value'],
                            'is_primary' => $phoneData['is_primary'] ?? false,
                        ]);
                        $updatedPhoneIds[] = $phone->id;
                    }
                }

                // Delete phones that were not in the update
                $phonesToDelete = array_diff($existingPhoneIds, $updatedPhoneIds);
                if (!empty($phonesToDelete)) {
                    ClientPhone::whereIn('id', $phonesToDelete)->delete();
                }
            }

            // Reload the client with relationships
            return $client->fresh(['phones', 'projects.agent']);
        });
    }
}
