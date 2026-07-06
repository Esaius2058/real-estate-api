<?php

namespace App\Http\Controllers\Api\v1; // ◄ Updated to match your structure

use App\Http\Controllers\Controller;
use App\Models\Agency; // Ensure this matches your model name
use Illuminate\Http\JsonResponse;

class AdminAgencyController extends Controller
{
    /**
     * Display a listing of all corporate agencies.
     */
    public function index(): JsonResponse
    {
        try {
            $agencies = Agency::latest()->get();

            return response()->json([
                'success' => true,
                'data' => $agencies
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve agencies matrix.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified agency workspace profile.
     */
    public function show($id): JsonResponse
    {
        try {
            $agency = Agency::find($id);

            if (!$agency) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agency workspace not found.'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $agency
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve agency profile.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}