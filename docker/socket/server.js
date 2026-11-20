'use strict';
const http = require('http');
const socketCluster = require('socketcluster-server');
const key = process.env.DDMAPS_SOCKET_AUTH_KEY;
if (!key || key.length < 32) throw new Error('A socket signing key is required');
const server = http.createServer((request, response) => {
    response.writeHead(request.url === '/health-check' ? 200 : 404);
    response.end(request.url === '/health-check' ? 'OK' : 'Not found');
});
const sockets = socketCluster.attach(server, {
    authKey: key, authAlgorithm: 'HS256', socketChannelLimit: 100,
    maxPayload: 1024 * 1024, allowClientPublish: true,
});
const valid = (token) => token && token.aud === 'ddmaps-socket' && token.exp > Date.now() / 1000;
const canSubscribe = (token, channel) => valid(token) && token.role !== 'publisher'
    && Array.isArray(token.channels) && token.channels.includes(channel);
sockets.setMiddleware(sockets.MIDDLEWARE_INBOUND, async (stream) => {
    for await (const action of stream) {
        const token = action.socket.authToken;
        if (action.type === action.SUBSCRIBE && !canSubscribe(token, action.channel)) {
            const error = new Error('Channel subscription forbidden'); error.name = 'ForbiddenError'; action.block(error);
        } else if (action.type === action.PUBLISH_IN && !(valid(token) && token.role === 'publisher')) {
            const error = new Error('Only server publishers may publish'); error.name = 'ForbiddenError'; action.block(error);
        } else { action.allow(); }
    }
});
sockets.setMiddleware(sockets.MIDDLEWARE_OUTBOUND, async (stream) => {
    for await (const action of stream) {
        if (action.type === action.PUBLISH_OUT && !canSubscribe(action.socket.authToken, action.channel)) action.block();
        else action.allow();
    }
});
(async () => { for await (const { error } of sockets.listener('error')) console.error(error.name, error.message); })();
server.listen(8000, '0.0.0.0');
