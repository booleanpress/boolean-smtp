import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';

/**
 * Laravel-style "hot file" dev switch.
 *
 * While `vite dev` runs, `<outDir>/hot` contains the dev-server origin and PHP loads
 * assets from there. Lifecycle rules:
 *   - the dev server writes the file on listen and removes it on exit — but only if the
 *     file still holds *its own* origin and nothing answers there any more, so a restart
 *     (which closes one server and opens the next on the same port) keeps the marker. A
 *     second dev server (e.g. one started on a spare port for the preview shell) leaves a
 *     live marker alone and never removes it;
 *   - `vite build` removes a marker only when the origin it points to is no longer
 *     accepting connections (a stale file from a crashed server), so building while a
 *     dev server is live does not silently switch the site to production assets.
 *
 * @param {{ outDir: string }} options
 */
export function hotFilePlugin({ outDir }) {
    const hotFile = path.join(outDir, 'hot');
    let command = 'build';
    let ownOrigin = null;
    let preserved = null; // live dev-server origin to restore after `emptyOutDir` wipes the out dir

    const read = () => {
        try {
            return fs.readFileSync(hotFile, 'utf8').trim();
        } catch {
            return null;
        }
    };

    const remove = () => {
        try {
            fs.rmSync(hotFile, { force: true });
        } catch {
            /* ignore */
        }
    };

    const removeIfOwn = () => {
        const current = read();
        if (current === null || current === ownOrigin) remove();
    };

    const isAlive = origin =>
        new Promise(resolve => {
            let url;
            try {
                url = new URL(origin);
            } catch {
                return resolve(false);
            }
            const socket = net.connect({ host: url.hostname, port: Number(url.port) || (url.protocol === 'https:' ? 443 : 80) });
            const done = alive => {
                socket.destroy();
                resolve(alive);
            };
            socket.setTimeout(400, () => done(false));
            socket.once('connect', () => done(true));
            socket.once('error', () => done(false));
        });

    return {
        name: 'boolean-smtp:hot-file',
        config(_config, env) {
            command = env.command;
        },
        async buildStart() {
            if (command !== 'build') return;
            const current = read();
            if (current === null) return;
            if (await isAlive(current)) {
                preserved = current;
                this.warn(`Keeping ${path.relative(process.cwd(), hotFile)} — a dev server is live at ${current}; WordPress will keep using it until it stops.`);
                return;
            }
            remove();
        },
        writeBundle() {
            if (command === 'build' && preserved) {
                fs.mkdirSync(outDir, { recursive: true });
                fs.writeFileSync(hotFile, preserved);
            }
        },
        configureServer(server) {
            // Only a real `vite dev` HTTP server owns the marker. Vitest (and any
            // middleware-mode embedding) also runs this hook but has no httpServer.
            if (!server.httpServer || process.env.VITEST) return;

            const write = async () => {
                const address = server.httpServer?.address();
                const protocol = server.config.server.https ? 'https' : 'http';
                const host = typeof address === 'object' && address?.address && address.address !== '::' && address.address !== '0.0.0.0'
                    ? address.address
                    : (typeof server.config.server.host === 'string' ? server.config.server.host : '127.0.0.1');
                const port = typeof address === 'object' && address?.port ? address.port : server.config.server.port;
                const origin = `${protocol}://${host}:${port}`;
                const current = read();
                if (current !== null && current !== origin && await isAlive(current)) {
                    server.config.logger.info(
                        `  hot-file: ${path.relative(process.cwd(), hotFile)} already points at a live dev server (${current}); this one (${origin}) will not drive WordPress.`,
                    );
                    return;
                }
                ownOrigin = origin;
                fs.mkdirSync(outDir, { recursive: true });
                fs.writeFileSync(hotFile, ownOrigin);
            };
            server.httpServer.once('listening', write);
            // A restart closes this server and opens the next one on the same origin a moment
            // later. Removing the marker here would leave WordPress on the built assets with a
            // live dev server running, so the marker only goes once that origin is really quiet.
            server.httpServer.once('close', () => {
                const timer = setTimeout(async () => {
                    if (ownOrigin !== null && await isAlive(ownOrigin)) return;
                    removeIfOwn();
                }, 1000);
                timer.unref?.();
            });
            for (const signal of ['SIGINT', 'SIGTERM', 'SIGHUP']) {
                process.once(signal, () => {
                    removeIfOwn();
                    process.exit(0);
                });
            }
            process.once('exit', removeIfOwn);
        },
    };
}
