FROM node:22-alpine

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm ci

# RTK-AI (token-optimized CLI proxy – needed because Claude Code hooks rewrite commands via rtk)
RUN apk add --no-cache curl ca-certificates \
    && curl -fsSL https://raw.githubusercontent.com/rtk-ai/rtk/refs/heads/master/install.sh | sh
ENV PATH="/root/.local/bin:${PATH}"

COPY . .

# next dev listens on 0.0.0.0 when HOSTNAME is set
ENV HOSTNAME=0.0.0.0
ENV PORT=3000

EXPOSE 3000

CMD ["npm", "run", "dev"]
