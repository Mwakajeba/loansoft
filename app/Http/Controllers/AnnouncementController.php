<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnnouncementController extends Controller
{
    public function index()
    {
        $companyId = current_company_id();
        $announcements = Announcement::where('company_id', $companyId)
            ->orderBy('publish_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('settings.announcements.index', compact('announcements'));
    }

    public function create()
    {
        return view('settings.announcements.create');
    }

    public function store(Request $request)
    {
        $companyId = current_company_id();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:5120', // 5MB max
            'publish_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:publish_date',
            'is_active' => 'sometimes|boolean',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('announcements', config('upload.storage_disk', 'public'));
        }

        Announcement::create([
            'company_id' => $companyId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'image_path' => $imagePath,
            'publish_date' => $validated['publish_date'],
            'end_date' => $validated['end_date'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('settings.announcements.index')
            ->with('success', 'Announcement created successfully.');
    }

    public function edit(Announcement $announcement)
    {
        $this->authorizeAnnouncement($announcement);
        return view('settings.announcements.edit', compact('announcement'));
    }

    public function update(Request $request, Announcement $announcement)
    {
        $this->authorizeAnnouncement($announcement);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:5120', // 5MB max
            'publish_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:publish_date',
            'is_active' => 'sometimes|boolean',
        ]);

        $data = [
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'publish_date' => $validated['publish_date'],
            'end_date' => $validated['end_date'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ];

        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($announcement->image_path) {
                Storage::disk(config('upload.storage_disk', 'public'))->delete($announcement->image_path);
            }
            $data['image_path'] = $request->file('image')->store('announcements', config('upload.storage_disk', 'public'));
        }

        $announcement->update($data);

        return redirect()->route('settings.announcements.index')
            ->with('success', 'Announcement updated successfully.');
    }

    public function destroy(Announcement $announcement)
    {
        $this->authorizeAnnouncement($announcement);

        if ($announcement->image_path) {
            Storage::disk(config('upload.storage_disk', 'public'))->delete($announcement->image_path);
        }
        $announcement->delete();

        return redirect()->route('settings.announcements.index')
            ->with('success', 'Announcement deleted successfully.');
    }

    private function authorizeAnnouncement(Announcement $announcement): void
    {
        if ($announcement->company_id !== current_company_id()) {
            abort(403, 'Unauthorized access to this announcement.');
        }
    }
}

