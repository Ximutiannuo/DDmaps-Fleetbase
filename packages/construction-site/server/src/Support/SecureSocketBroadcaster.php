<?php

namespace Fleetbase\ConstructionSite\Support;

use Illuminate\Contracts\Broadcasting\Broadcaster;
use WebSocket\Client;


final class SecureSocketBroadcaster implements Broadcaster
{
    public function auth($request) { abort(403, 'Use the authenticated socket-token endpoint.'); }
    public function validAuthenticationResponse($request, $result) { abort(403); }

    private function reply(Client $client): array
    {
        do {
            $message = $client->receive();
            $text = is_object($message) && method_exists($message, 'getContent') ? $message->getContent() : (string) $message;
            if ($text === '') $client->send(''); // SocketCluster heartbeat.
        } while ($text === '');
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $options = config('broadcasting.connections.socketcluster.options', []);
        $host = $options['host'] ?? env('SOCKETCLUSTER_HOST', 'socket');
        $port = $options['port'] ?? env('SOCKETCLUSTER_PORT', 8000);
        $client = new Client('ws://' . $host . ':' . $port . '/socketcluster/', ['timeout' => 5]);
        try {
            $client->send(json_encode(['event' => '#handshake', 'cid' => 1,
                'data' => ['authToken' => SiteSocketToken::sign(['role' => 'publisher'])]]));
            $reply = $this->reply($client);
            if (!empty($reply['error']) || empty($reply['data']['isAuthenticated'])) throw new \RuntimeException('Socket publisher authentication failed.');
            foreach ($channels as $index => $channel) {
                $client->send(json_encode(['event' => '#publish', 'cid' => $index + 2,
                    'data' => ['channel' => (string) $channel, 'data' => $payload]]));
                $reply = $this->reply($client);
                if (!empty($reply['error'])) throw new \RuntimeException('Socket publication was rejected.');
            }
        } finally { $client->close(); }
    }
}
