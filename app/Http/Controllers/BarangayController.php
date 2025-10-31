<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Services\BarangayCreationService;
use App\Http\Requests\BarangayRequest;
use App\Http\Resources\BarangayResource;
use App\Services\BarangayRetrievalService;

class BarangayController extends Controller
{
    private BarangayRetrievalService $barangayRetrievalService;
    public function __construct(BarangayRetrievalService $barangayRetrievalService)
    {
        $this->barangayRetrievalService = $barangayRetrievalService;
    }
    public function getAllBarangay()
    {
        return BarangayResource::collection($this->barangayRetrievalService->getAllBarangays());
    }
    public function getBarangayByCity($city)
    {
        return BarangayResource::collection($this->barangayRetrievalService->getBarangaysByCity($city));
    }
    public function getBarangayByProvince($province)
    {
        return BarangayResource::collection($this->barangayRetrievalService->getBarangaysByProvince($province));
    }
    public function getBarangayById($id)
    {
        return new BarangayResource($this->barangayRetrievalService->getBarangayById($id));
    }
    public function searchBarangayByName($name)
    {
        return BarangayResource::collection($this->barangayRetrievalService->searchBarangaysByName($name));
    }
    public function createBarangay(BarangayRequest $request) {
        return (new BarangayCreationService($request->validated()))->createBarangay();
    }
    public function updateBarangay(BarangayRequest $request, $id) {
        return (new BarangayCreationService($request->validated()))->updateBarangay($id);
    }
    public function deleteBarangay($id) {
        return (new BarangayCreationService([]))->deleteBarangay($id);
    }


}
