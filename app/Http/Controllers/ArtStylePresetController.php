<?php

namespace App\Http\Controllers;

use App\Models\ArtStylePreset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ArtStylePresetController extends Controller
{
    /**
     * List all custom presets for the current user.
     */
    public function index()
    {
        $presets = ArtStylePreset::where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'presets' => $presets
        ]);
    }

    /**
     * Create a new custom preset.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'required|string|max:5000',
            'icon' => 'nullable|string|max:20',
            'color' => 'nullable|string|max:30',
        ]);

        $preset = ArtStylePreset::create([
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'description' => $validated['description'],
            'icon' => $validated['icon'] ?? '🎨',
            'color' => $validated['color'] ?? 'purple',
        ]);

        return response()->json([
            'success' => true,
            'preset' => $preset,
            'message' => 'Đã tạo preset thành công!'
        ]);
    }

    /**
     * Update an existing preset.
     */
    public function update(Request $request, ArtStylePreset $preset)
    {
        // Ensure user owns this preset
        if ($preset->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'description' => 'sometimes|required|string|max:5000',
            'icon' => 'nullable|string|max:20',
            'color' => 'nullable|string|max:30',
        ]);

        $preset->update($validated);

        return response()->json([
            'success' => true,
            'preset' => $preset,
            'message' => 'Đã cập nhật preset!'
        ]);
    }

    /**
     * Delete a custom preset.
     */
    public function destroy(ArtStylePreset $preset)
    {
        // Ensure user owns this preset
        if ($preset->user_id !== Auth::id()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        $preset->delete();

        return response()->json([
            'success' => true,
            'message' => 'Đã xoá preset!'
        ]);
    }
}
