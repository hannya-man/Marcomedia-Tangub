<?php
namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::withCount('products')->whereNull('archived_at')->inShopOrder()->get();
        $archivedCount = Category::whereNotNull('archived_at')->count();

        return view('inventory.categories', compact('categories', 'archivedCount'));
    }

    public function store(Request $request)
    {
        // A name with no letter or number ("!!!") has an empty slug, and every such name was one category.
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/[\pL\pN]/u'],
        ], [
            'name.regex' => 'Use a name with letters or numbers.',
        ]);

        $category = Category::firstOrCreate(
            ['slug' => str($validated['name'])->slug()],
            ['name' => $validated['name']]
        );

        // The same name as an archived category brings that one back, instead of saying "added"
        // while nothing shows up.
        if ($category->archived_at) {
            $category->update(['archived_at' => null]);
            return redirect()->route('inventory.categories')->with('success', "'{$category->name}' was in the archive. It's back.");
        }
        if (! $category->wasRecentlyCreated) {
            return redirect()->route('inventory.categories')->with('error', "'{$category->name}' is already a category.");
        }

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
