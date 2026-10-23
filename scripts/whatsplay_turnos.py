#!/usr/bin/env python3
"""
WhatsPlay bridge for the Donemos API (whatsplay-automation skill).

Modes:
  vincular  Start WhatsApp Web headless, write the login QR (base64 PNG) and
            the state to --estado-dir so the API can show it in the app.
  enviar    Send one text message to --to (549... number) and print
            "RESULTADO OK" or "RESULTADO ERROR <reason>".

WhatsPlay's built-in QR web server binds 0.0.0.0:8000. It is disabled here:
it collides with the API port and would let anyone on the network grab the
QR and take over the hemocentro's WhatsApp session.

Run with the skill's environment, e.g.:
  uv run --project ~/proyectos/personal/whatsapp-skill python scripts/whatsplay_turnos.py enviar ...
"""
import argparse
import asyncio
import base64
import json
import sys
import time
from pathlib import Path

import whatsplay.state_manager as state_manager
from whatsplay import Client
from whatsplay.auth import LocalProfileAuth

# Never start WhatsPlay's public QR server.
state_manager.show_qr_window = lambda *_args, **_kwargs: None
state_manager.update_qr_code = lambda *_args, **_kwargs: None
state_manager.close_qr_window = lambda *_args, **_kwargs: None


def write_estado(estado_dir: Path, estado: str, qr: bytes | None = None, detalle: str | None = None) -> None:
    estado_dir.mkdir(parents=True, exist_ok=True)
    payload = {
        "estado": estado,
        "qr": base64.b64encode(qr).decode() if qr else None,
        "detalle": detalle,
        "actualizado": int(time.time()),
    }
    tmp = estado_dir / "estado.json.tmp"
    tmp.write_text(json.dumps(payload))
    tmp.replace(estado_dir / "estado.json")


async def vincular(args) -> int:
    estado_dir = Path(args.estado_dir)
    client = Client(auth=LocalProfileAuth(data_dir=args.session), headless=True)
    write_estado(estado_dir, "iniciando")
    deadline = time.time() + args.timeout

    @client.event("on_qr")
    async def on_qr(qr):
        write_estado(estado_dir, "esperando_qr", qr)

    @client.event("on_qr_change")
    async def on_qr_change(qr):
        write_estado(estado_dir, "esperando_qr", qr)

    @client.event("on_logged_in")
    async def on_logged_in():
        write_estado(estado_dir, "vinculado")
        await client.stop()

    @client.event("on_error")
    async def on_error(error):
        write_estado(estado_dir, "error", detalle=str(error))
        await client.stop()

    async def watchdog():
        while time.time() < deadline:
            await asyncio.sleep(2)
        write_estado(estado_dir, "vencido", detalle="No se escaneó el QR a tiempo.")
        await client.stop()

    task = asyncio.create_task(watchdog())
    await client.start()
    task.cancel()
    return 0


async def enviar(args) -> int:
    client = Client(auth=LocalProfileAuth(data_dir=args.session), headless=True)
    result = {"ok": False, "error": "WhatsApp no inició sesión"}

    @client.event("on_logged_in")
    async def on_logged_in():
        try:
            # Phone numbers need open_via_url=True (skill gotcha).
            ok = await client.send_message(args.to, args.message, open_via_url=True)
            result.update(ok=bool(ok), error=None if ok else "send_message devolvió False")
            # Wait for the server tick before closing (skill gotcha).
            await asyncio.sleep(8)
        except Exception as e:  # noqa: BLE001 - report any failure to the API
            result.update(ok=False, error=str(e))
        await client.stop()

    @client.event("on_qr")
    async def on_qr(_qr):
        result.update(ok=False, error="La sesión de WhatsApp no está vinculada; vinculala desde la app.")
        await client.stop()

    @client.event("on_error")
    async def on_error(error):
        result.update(ok=False, error=str(error))
        await client.stop()

    await client.start()
    print("RESULTADO OK" if result["ok"] else f"RESULTADO ERROR {result['error']}", flush=True)
    return 0 if result["ok"] else 1


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("modo", choices=["vincular", "enviar"])
    parser.add_argument("--session", required=True)
    parser.add_argument("--estado-dir")
    parser.add_argument("--timeout", type=int, default=180)
    parser.add_argument("--to")
    parser.add_argument("--message")
    args = parser.parse_args()

    if args.modo == "vincular":
        if not args.estado_dir:
            parser.error("vincular necesita --estado-dir")
        return asyncio.run(vincular(args))
    if not (args.to and args.message):
        parser.error("enviar necesita --to y --message")
    return asyncio.run(enviar(args))


if __name__ == "__main__":
    sys.exit(main())
