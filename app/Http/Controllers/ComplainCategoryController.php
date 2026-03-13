<?php

namespace App\Http\Controllers;

use App\Models\ComplainCategory;
use Illuminate\Http\Request;

class ComplainCategoryController extends Controller
{
    public function index()
    {
        $categories = ComplainCategory::orderBy('priority', 'desc')->orderBy('name', 'asc')->get();
        return view('settings.complain-categories.index', compact('categories'));
    }

    public function create()
    {
        return view('settings.complain-categories.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|integer|min:0',
        ]);

        ComplainCategory::create([
            'name' => $request->name,
            'description' => $request->description,
            'priority' => (int) ($request->priority ?? 0),
        ]);

        return redirect()->route('settings.complain-categories.index')
            ->with('success', 'Complain category created successfully.');
    }

    public function edit(ComplainCategory $complainCategory)
    {
        return view('settings.complain-categories.edit', compact('complainCategory'));
    }

    public function update(Request $request, ComplainCategory $complainCategory)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|integer|min:0',
        ]);

        $complainCategory->update([
            'name' => $request->name,
            'description' => $request->description,
            'priority' => (int) ($request->priority ?? 0),
        ]);

        return redirect()->route('settings.complain-categories.index')
            ->with('success', 'Complain category updated successfully.');
    }

    public function destroy(ComplainCategory $complainCategory)
    {
        if ($complainCategory->complains()->exists()) {
            return redirect()->route('settings.complain-categories.index')
                ->with('error', 'Cannot delete: this category has complaints.');
        }
        $complainCategory->delete();
        return redirect()->route('settings.complain-categories.index')
            ->with('success', 'Complain category deleted.');
    }
}
