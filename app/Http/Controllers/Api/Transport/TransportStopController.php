<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TransportStop;
use Illuminate\Http\Request;

class TransportStopController extends Controller
{
    public function index(Request $request)
    {
        $query = TransportStop::query()->orderBy('stop_order');

        if ($request->filled('route_id')) {
            $query->where('route_id', $request->integer('route_id'));
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'route_id' => 'required|exists:transport_routes,id',
            'stop_name' => 'required|string',
            'distance_km' => 'nullable|numeric|min:0',
            'fee_amount' => 'required|numeric|min:0',
        ]);

        $nextOrder = TransportStop::where('route_id', $data['route_id'])->max('stop_order');
        $nextOrder = $nextOrder ? $nextOrder + 1 : 1;

        $stop = TransportStop::create([
            'route_id' => $data['route_id'],
            'stop_name' => $data['stop_name'],
            'fee_amount' => (string) $data['fee_amount'],
            'distance_km' => $data['distance_km'] ?? null,
            'pickup_time' => '08:00:00',
            'drop_time' => '15:00:00',
            'stop_order' => $nextOrder,
            'active' => true,
        ]);

        AuditLog::log('create', $stop, null, $stop->toArray(), 'Transport stop created');

        return response()->json($stop, 201);
    }
}
