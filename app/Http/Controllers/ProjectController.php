<?php

namespace App\Http\Controllers;

use App\Models\DubSyncProject;
use App\Models\YoutubeChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    /**
     * Display a listing of projects
     */
    public function index()
    {
        // Only select needed columns to reduce data load
        $page = request()->get('page', 1);
        $perPage = request()->get('per_page', 15);

        // Validate per_page to prevent abuse
        $allowedPerPage = [10, 15, 25, 50, 100];
        if (!in_array($perPage, $allowedPerPage)) {
            $perPage = 15;
        }

        $cacheKey = 'projects.list.page.' . auth()->id() . '.' . $page . '.' . $perPage;

        // Cache for 10 minutes
        $projects = \Cache::remember($cacheKey, 600, function () use ($perPage) {
            return DubSyncProject::select([
                'id',
                'video_id',
                'youtube_url',
                'youtube_title',
                'youtube_title_vi',
                'youtube_description',
                'youtube_thumbnail',
                'youtube_duration',
                'status',
                'created_at',
                'updated_at'
            ])->where('user_id', auth()->id())->latest()->paginate($perPage);
        });

        return view('projects.index', compact('projects'));
    }

    /**
     * Show the form for creating a new project
     */
    public function create()
    {
        $youtubeChannel = null;
        $youtubeChannelId = request()->query('youtube_channel_id');

        if ($youtubeChannelId) {
            $youtubeChannel = YoutubeChannel::find($youtubeChannelId);
        }

        return view('projects.create', compact('youtubeChannel'));
    }

    /**
     * Store a newly created project in storage
     */
    public function store(Request $request)
    {
        $request->validate([
            'youtube_url' => 'required|url',
            'youtube_channel_id' => 'nullable|exists:youtube_channels,id',
        ]);

        try {
            // Extract video ID
            $videoId = $this->extractVideoId($request->youtube_url);
            if (!$videoId) {
                return back()->withErrors(['youtube_url' => 'Invalid YouTube URL']);
            }

            // Create project
            $project = DubSyncProject::create([
                'user_id' => auth()->id(),
                'youtube_channel_id' => $request->input('youtube_channel_id'),
                'video_id' => $videoId,
                'youtube_url' => $request->youtube_url,
                'status' => 'new'
            ]);

            // Clear cache
            $this->clearProjectListCache();

            return redirect()->route('projects.show', $project->id)
                ->with('success', 'Project created successfully. Now processing transcript...');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Display the specified project
     */
    public function show(DubSyncProject $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }
        return view('projects.show', compact('project'));
    }

    /**
     * Show the form for editing the specified project
     */
    public function edit(DubSyncProject $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }
        return view('projects.edit', compact('project'));
    }

    /**
     * Update the specified project in storage
     */
    public function update(Request $request, DubSyncProject $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'youtube_url' => 'sometimes|url',
        ]);

        try {
            $project->update($request->only(['youtube_url']));

            // Clear cache
            $this->clearProjectListCache();

            return redirect()->route('projects.show', $project->id)
                ->with('success', 'Project updated successfully');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Remove the specified project from storage
     */
    public function destroy(DubSyncProject $project)
    {
        if ($project->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        try {
            // Delete associated files
            $this->deleteProjectFiles($project);
            $project->delete();

            // Clear cache
            $this->clearProjectListCache();

            return redirect()->route('projects.index')
                ->with('success', 'Project deleted successfully');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'project_ids' => 'required|array',
            'project_ids.*' => 'integer|exists:dub_sync_projects,id',
        ]);

        $ids = array_values(array_unique($validated['project_ids']));

        $projects = DubSyncProject::whereIn('id', $ids)
            ->where('user_id', auth()->id())
            ->get();

        $deleted = 0;

        foreach ($projects as $project) {
            $this->deleteProjectFiles($project);
            $project->delete();
            $deleted++;
        }

        $this->clearProjectListCache();

        return redirect()->back()->with('success', "Deleted {$deleted} project(s) successfully.");
    }

    /**
     * Extract video ID from YouTube URL
     */
    private function extractVideoId($url)
    {
        $pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i';

        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Delete all files associated with a project
     */
    private function deleteProjectFiles(DubSyncProject $project)
    {
        // Delete audio segments
        if ($project->audio_segments) {
            $audioSegments = $project->audio_segments;
            foreach ($audioSegments as $segment) {
                if (isset($segment['audio_path']) && \Storage::exists($segment['audio_path'])) {
                    \Storage::delete($segment['audio_path']);
                }
            }
        }

        // Delete final audio
        if ($project->final_audio_path && \Storage::exists($project->final_audio_path)) {
            \Storage::delete($project->final_audio_path);
        }

        // Delete exported files
        if ($project->exported_files) {
            $exportedFiles = $project->exported_files;
            foreach ($exportedFiles as $filePath) {
                if (\Storage::exists($filePath)) {
                    \Storage::delete($filePath);
                }
            }
        }
    }

    /**
     * Clear all project list cache
     */
    private function clearProjectListCache()
    {
        $userId = auth()->id();
        $pageCount = 10; // Clear first 10 pages as safeguard
        $perPageOptions = [10, 15, 25, 50, 100];

        for ($page = 1; $page <= $pageCount; $page++) {
            foreach ($perPageOptions as $perPage) {
                \Cache::forget('projects.list.page.' . $userId . '.' . $page . '.' . $perPage);
            }
        }

        // Backward compatibility: clear any legacy keys without per_page.
        for ($page = 1; $page <= $pageCount; $page++) {
            \Cache::forget('projects.list.page.' . $userId . '.' . $page);
        }

        // Backward compatibility: clear very old keys without user id.
        for ($page = 1; $page <= $pageCount; $page++) {
            \Cache::forget('projects.list.page.' . $page);
        }
    }
}
