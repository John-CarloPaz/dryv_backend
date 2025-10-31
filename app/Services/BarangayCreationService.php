<?php
namespace App\Services;
use App\Models\Barangay;
use Illuminate\Http\JsonResponse;

class BarangayCreationService
{
    public array $barangayData;
    public function __construct(array $data) {
        $this->barangayData = $data;
    }
    public function createBarangay(): JsonResponse
    {
        $barangay = new Barangay();
        $barangay->name = $this->barangayData['name'];
        $barangay->city = $this->barangayData['city'];
        $barangay->province = $this->barangayData['province'];
        $barangay->latitude = $this->barangayData['latitude'];
        $barangay->longitude = $this->barangayData['longitude'];
        $barangay->save();

        return response()->json([
            'message' => 'Barangay created successfully',
            'barangay' => $barangay,
        ]);
    }

    public function bulkCreateBarangays(string $jsonFilePath): JsonResponse
    {
        if (!file_exists($jsonFilePath)) {
            return response()->json(['message' => 'JSON file not found'], 404);
        }

        $jsonData = file_get_contents($jsonFilePath);
        $barangays = json_decode($jsonData, true);

        if (!is_array($barangays)) {
            return response()->json(['message' => 'Invalid JSON structure'], 400);
        }

        DB::beginTransaction();
        try {
            foreach ($barangays as $barangayData) {
                Barangay::create([
                    'name' => $barangayData['name'],
                    'city' => $barangayData['city'],
                    'province' => $barangayData['province'],
                    'latitude' => $barangayData['latitude'],
                    'longitude' => $barangayData['longitude'],
                ]);
            }
            DB::commit();

            return response()->json(['message' => 'Barangays created successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error creating barangays', 'error' => $e->getMessage()], 500);
        }
    }

    public function updateBarangay($id): JsonResponse {
        $barangay = Barangay::where('id', $id)->first();
        if (!$barangay) {
            return response()->json(['message' => 'Barangay not found'], 404);
        }
        $barangay->fill([
            'name' => $this->barangayData['name'],
            'city' => $this->barangayData['city'],
            'province' => $this->barangayData['province'],
            'latitude' => $this->barangayData['latitude'],
            'longitude' => $this->barangayData['longitude']
        ]);
        $barangay->save();

        return response()->json([
            'message' => 'Barangay updated successfully',
            'barangay' => $barangay,
        ]);
    }

    public function deleteBarangay($id): JsonResponse {
        $department = Barangay::where('id', $id)->first();
        $department->delete();

        return response()->json([
            'message' => 'Department deleted successfully',
        ]);
    }
}
