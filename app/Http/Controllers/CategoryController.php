<?php
namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::withCount('products')->whereNull('archived_at')->orderBy('name')->get();
        $archivedCount = Category::whereNotNull('archived_at')->count();

        return view('inventory.categories', compact('categories', 'archivedCount'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        Category::firstOrCreate(
            ['slug' => str($validated['name'])->slug()],
            ['name' => $validated['name']]
        );

        return redirect()->route('inventory.categories')->with('success', 'Category added.');
    }

    // "Delete" archives instead of removing the record — same pattern as
    // everything else in this app. Products already assigned to this
    // category keep their link; the category just stops being offered
    // for new products.
    public function destroy(Category $category)
    {
        $category->update(['archived_at' => now()]);
        return back()->with('success', "'{$category->name}' archived.");
    }

    public function restore(Category $category)
    {
        $category->update(['archived_at' => null]);
        return back()->with('success', "'{$category->name}' restored.");
    }

    public function archive()
    {
        $archivedCategories = Category::withCount('products')->whereNotNull('archived_at')->orderByDesc('archived_at')->get();
        return view('inventory.categories-archive', compact('archivedCategories'));
    }
}
