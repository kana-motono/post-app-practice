<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Post;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index()
    {
        // 同時刻のポストは id の大きい（新しい）方を先に出す
        $posts = Post::with(['user', 'category'])->latest()->latest('id')->get();

        $categories = Category::orderBy('id')->get();

        return view('posts.index', compact('posts', 'categories'));
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPostData($request);

        // 投稿者は常にログイン中のユーザー（リクエストの user_id は受け取らない）
        Post::create([...$validated, 'user_id' => $request->user()->id]);

        return redirect()->route('posts.index');
    }

    public function show(Post $post)
    {
        $post->load(['user', 'category']);

        // 古い順に並べ、同時刻なら id 順にする
        $replies = $post->replies()->with('user')->orderBy('created_at')->orderBy('id')->get();

        return view('posts.show', compact('post', 'replies'));
    }

    public function edit(Post $post)
    {
        $this->authorize('update', $post);

        $categories = Category::orderBy('id')->get();

        return view('posts.edit', compact('post', 'categories'));
    }

    public function update(Request $request, Post $post)
    {
        $this->authorize('update', $post);

        $validated = $this->validatedPostData($request);

        $post->update($validated);

        return redirect()->route('posts.index');
    }

    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);

        $post->delete();

        return redirect()->route('posts.index');
    }

    private function validatedPostData(Request $request): array
    {
        // ブラウザは改行を CRLF で送るため、LF に揃えてから文字数を数える
        // 配列などの文字列でない値はそのまま string ルールに判定させる
        if (is_string($request->input('content'))) {
            $request->merge([
                'content' => str_replace("\r\n", "\n", $request->input('content')),
            ]);
        }

        return $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:140',
            'category_id' => 'required|integer|exists:categories,id',
        ], [
            'content.max' => '本文は140字以内で入力してください。',
        ]);
    }
}
