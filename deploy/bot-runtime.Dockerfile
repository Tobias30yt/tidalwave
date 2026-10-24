FROM node:22-bookworm-slim
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-venv python3-pip default-jdk-headless sudo ca-certificates curl git build-essential \
    && python3 -m venv /opt/venv && mkdir -p /app && chown 1000:1000 /app \
    && rm -rf /var/lib/apt/lists/*
ENV PATH="/opt/venv/bin:$PATH" PYTHONUNBUFFERED=1
WORKDIR /app
