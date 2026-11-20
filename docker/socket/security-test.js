'use strict';
const assert = require('assert/strict');
const crypto = require('crypto');
const client = require('socketcluster-client');
const key = process.env.DDMAPS_SOCKET_AUTH_KEY;
const token = (claims) => {
    const enc = (x) => Buffer.from(JSON.stringify(x)).toString('base64url');
    const body = enc({ alg: 'HS256', typ: 'JWT' }) + '.' + enc({ aud: 'ddmaps-socket', iat: Math.floor(Date.now() / 1000), exp: Math.floor(Date.now() / 1000) + 60, ...claims });
    return body + '.' + crypto.createHmac('sha256', key).update(body).digest('base64url');
};
const sockets = [];
async function connect(claims) {
    const socket = client.create({ hostname: 'localhost', port: 8000, autoReconnect: false, ackTimeout: 2000,
        authEngine: { loadToken: async () => claims ? token(claims) : null, saveToken: async () => {}, removeToken: async () => {} } });
    sockets.push(socket);
    await socket.listener('connect').once(3000);
    return socket;
}
async function forbidden(socket, channel) {
    const subscription = socket.subscribe(channel);
    await subscription.listener('subscribeFail').once(3000);
    subscription.unsubscribe();
}
(async () => {
    const anonymous = await connect();
    await forbidden(anonymous, 'company.test'); console.log('PASS anonymous subscription denied');
    await assert.rejects(anonymous.invoke('#publish', { channel: 'company.test', data: {} }));
    console.log('PASS anonymous publication denied');
    const member = await connect({ role: 'member', channels: ['company.test'] });
    await forbidden(member, 'company.other'); console.log('PASS cross-company subscription denied');
    await assert.rejects(member.invoke('#publish', { channel: 'company.test', data: {} })); console.log('PASS member publication denied');
    const driver = await connect({ role: 'driver', channels: ['road-network.test'] });
    await forbidden(driver, 'company.test'); console.log('PASS driver notification subscription denied');
    const channel = member.subscribe('company.test');
    await channel.listener('subscribe').once(3000);
    const message = channel.createConsumer();
    const publisher = await connect({ role: 'publisher' });
    await publisher.invoke('#publish', { channel: 'company.test', data: { probe: 'secured' } });
    assert.deepEqual((await message.next(3000)).value, { probe: 'secured' });
    message.kill(); console.log('PASS authenticated publication and subscription');
    const expired = await connect({ role: 'member', channels: ['company.test'], exp: Math.floor(Date.now() / 1000) - 1 });
    await forbidden(expired, 'company.test'); console.log('PASS expired token denied');
})().catch((error) => { console.error(error.name, error.message); process.exitCode = 1; })
.finally(() => { sockets.forEach((socket) => socket.disconnect()); });
