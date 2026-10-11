<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Reply;
use Illuminate\Http\Request;

class ReplyController extends Controller
{
    public function store(Request $request, Post $post)
    {
        // ブラウザは改行を CRLF で送るため、LF に揃えてから文字数を数える
        $request->merge([
            'content' => str_replace("\r\n", "\n", (string) $request->input('content')),
        ]);

        $validated = $request->validate([
            'content' => 'required|string|max:140',
        ], [
            'content.required' => 'リプライを入力してください。',
            'content.string' => 'リプライは文字列で入力してください。',
            'content.max' => 'リプライは140字以内で入力してください。',
        ]);

        $post->replies()->create([
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
        ]);

        return redirect()->route('posts.show', $post);
    }

    public function destroy(Reply $reply)
    {
        $this->authorize('delete', $reply);

        $reply->delete();

        return redirect()->route('posts.show', $reply->post_id);
    }
}
