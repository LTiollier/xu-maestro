FROM node:22-alpine

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci

# RTK-AI (token-optimized CLI proxy)
RUN apk add --no-cache curl ca-certificates \
    && curl -fsSL https://raw.githubusercontent.com/rtk-ai/rtk/refs/heads/master/install.sh | sh \
    && mv /root/.local/bin/rtk /usr/local/bin/rtk

# Create a non-root user (UID 1000 is often taken by 'node' user in Alpine)
RUN (deluser --remove-home node || true) && \
    (delgroup node || true) && \
    addgroup -g 1000 maestro && \
    adduser -u 1000 -G maestro -D maestro

COPY . .
RUN chown -R maestro:maestro /app

USER maestro

# next dev listens on 0.0.0.0 when HOSTNAME is set
ENV HOSTNAME=0.0.0.0
ENV PORT=3000

EXPOSE 3000

CMD ["npm", "run", "dev"]
