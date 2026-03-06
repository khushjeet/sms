<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TransportRoute;
use Illuminate\Http\Request;

class TransportRouteController extends Controller
{
    public function index()
    {
        return TransportRoute::orderBy('route_name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'route_name' => 'required|string',
            'vehicle_number' => 'required|string',
            'driver_name' => 'nullable|string',
        ]);

        $route = TransportRoute::create([
            'route_name' => $data['route_name'],
            'vehicle_number' => $data['vehicle_number'],
            'driver_name' => $data['driver_name'] ?? null,
            'route_number' => 'R-' . now()->format('YmdHis') . '-' . random_int(100, 999),
            'fee_amount' => '0',
            'status' => 'active',
            'active' => true,
        ]);

        AuditLog::log('create', $route, null, $route->toArray(), 'Transport route created');

        return response()->json($route, 201);
    }
}
