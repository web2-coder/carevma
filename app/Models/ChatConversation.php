<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ChatConversation extends Model {
    protected $guarded = [];
    public function participants(){ return $this->belongsToMany(User::class, 'chat_participants')->withPivot('last_read_at')->withTimestamps(); }
    public function messages(){ return $this->hasMany(ChatMessage::class, 'conversation_id'); }
}
