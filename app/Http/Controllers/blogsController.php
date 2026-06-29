<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\Validator;

class blogsController extends Controller
{
    public function index(Request $request)
    {
        $category = DB::table('blog_categories')->get();

        $selectedCategory = $request->input('category');

        $blogsQuery = DB::table('blogs');
        $blogs_unpaginated = DB::table('blogs');

        if ($selectedCategory) {
            $blogsQuery->where('category_id', $selectedCategory);
        }

        $blogs = $blogsQuery->paginate(15);


        return view('front.blog-posts.blogs', [
            'category' => $category,
            'blogs' => $blogs,
            'selectedCategory' => $selectedCategory,
            'blogs_unpaginated' => $blogs_unpaginated
        ]);
    }

    public function show($id)
    {
        // Fetch the blog content from the database using the query builder

        $blog = DB::table('blogs')->where('id', $id)->first();

        // Check if the blog exists
        if (!$blog) {
            abort(404); // Return a 404 error if the blog is not found
        }
        $blog->content = html_entity_decode($blog->content);

        // Pass the blog content to the view
        return view('front.blog-posts.blog-show', ['blog' => $blog]);
    }

    public function blogsAdmin()
    {
        if (Auth::check() && Auth::user()->role == 'admin') {
            $id = Auth::user()->id;
            $user = User::where('id', $id)->first();
            $category_blog = DB::table('blog_categories')->get();
            $blogs = DB::table('blogs')->orderBy('id', 'desc')->paginate(5);
            return view('front.account.profiles.blog-edit', ['category_blog' => $category_blog], ['blogs' => $blogs]);
        } else {
            // Handle the case when the user is not authenticated or not an admin
            return redirect()->route('account.login');
        }
    }
    public function addCategory(Request $request)
    {
        $request->validate([
            'category_name' => 'required|unique:blog_categories',
        ]);

        DB::table('blog_categories')->insert([
            'category_name' => $request->category_name,
            'created_at' => now(),
        ]);

        $category = DB::table('blog_categories')->where('category_name', $request->category_name)->first();

        return redirect()->back()->with('success', "Success!, Category ID: {$category->id}, Category Name: {$category->category_name}");
    }
    public function uploadBlog(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'document' => 'required|mimes:docx,pdf|max:10240',
            'title' => 'required',
            'category_id' => 'required',
        ]);
        if ($validator->fails()) {
            session()->flash('info', 'No uploads were made, please check the form!');
            $request->validate([
                'document' => 'required|mimes:docx,pdf|max:10240',
                'title' => 'required',
                'category_id' => 'required',
            ]);
            return redirect()->back();
        }

        $file = $request->file('document');
        $htmlContent = '';

        if ($file->getClientOriginalExtension() == 'docx') {
            $phpWord = IOFactory::load($file->getPathname());
            $xmlWriter = IOFactory::createWriter($phpWord, 'HTML');
            ob_start();
            $xmlWriter->save('php://output');
            $htmlContent = ob_get_clean();
        } elseif ($file->getClientOriginalExtension() == 'pdf') {
            $parser = new Parser();
            $pdf = $parser->parseFile($file->getPathname());
            $text = $pdf->getText();
            $htmlContent = nl2br(e($text)); // Convert newlines to <br> and escape HTML entities
        }

        // Load the HTML content

        if ($request->hasFile('image')) {
            // If the user uploaded an image, store it
            $imagePath = $request->file('image')->store('images/blogs-images', 'public');
        } else {
            // If no image was uploaded, assign a random image
            $imagePath = 'images/blogs-images/default-image.jpg'; // Replace with the path to your default random image
        }

        // Now you can save the $htmlContent to your database
        DB::table('blogs')->insert([
            'title' => $request->input('title'),
            'content' => $htmlContent,
            'created_at' => now(),
            'user_id' => Auth::id(),
            'category_id' => $request->input('category_id'),
            'image' => $imagePath,
        ]);

        return redirect()->back()->with('success', 'Blog uploaded successfully!');
    }
    public function deleteBlog($id)
    {
        if (Auth::check() && Auth::user()->role == 'admin') {
            if (!$id) {
                return redirect()->back()->withErrors('No blog is selected.');
            }

            // Perform the deletion
            DB::table('blogs')->where('id', $id)->delete();
            
            return redirect()->route('blogs')->with('success', 'Blog deleted successfully!');
        } else {
            // Handle the case when the user is not authenticated or not an admin
            return redirect()->route('account.login');
        }
        
    }
}
