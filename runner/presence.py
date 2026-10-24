"""Gateway presence only. Signed /verify interactions stay on the PHP endpoint."""
import logging
import subprocess
from pathlib import Path

import discord

ROOT = Path(__file__).resolve().parents[1]
token = subprocess.run(
    ['/usr/bin/php', '-r', '$c=require $argv[1]; echo $c["bot_token"];', str(ROOT / 'config/auth.php')],
    check=True, capture_output=True, text=True,
).stdout.strip()
if not token:
    raise SystemExit('Verification bot token is missing')

class Presence(discord.Client):
    async def on_ready(self):
        logging.getLogger('tidalwave').warning('Verification bot connected; presence is online.')

client = Presence(intents=discord.Intents.none(), status=discord.Status.online,
                  activity=discord.Game(name='/verify · TidalWave'))
client.run(token, log_level=logging.WARNING)
