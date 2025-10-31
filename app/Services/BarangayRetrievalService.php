<?php

namespace App\Services;
use App\Models\Barangay;
use App\Models\Noah;

class BarangayRetrievalService {
    public function getAllBarangays() {
        return Noah::all();
    }
    public function getBarangayById($id) {
        if (!$id) {
            return response()->json(['message' => 'Barangay not Found'], 400);
        }
        return Barangay::find($id);
    }
    public function getBarangaysByCity($city) {
        if (!$city) {
            return response()->json(['message' => 'Barangay not Found'], 400);
        }
        return Barangay::where('city', $city)->get();
    }
    public function getBarangaysByProvince($province) {
        if (!$province) {
            return response()->json(['message' => 'Barangay not Found'], 400);
        }
        return Barangay::where('province', $province)->get();
    }

    public function searchBarangaysByName($name) {
        if (!$name) {
            return response()->json(['message' => 'Barangay not Found'], 400);
        }
        return Barangay::where('name', 'LIKE', "%$name%")->get();
    }
}
