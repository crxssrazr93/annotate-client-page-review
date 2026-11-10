FROM node:22-alpine
WORKDIR /app
# Pinned upstream reference server, patched at build time (see patch.cjs):
#  - reviews read from an external Map (add reviews without a restart)
#  - per-review `shared` board (everyone views all; edits stay owner-only)
COPY patch.cjs ./patch.cjs
RUN npm init -y >/dev/null && npm install --omit=dev @reviewjs/annotate@1.6.0 >/dev/null \
 && cp node_modules/@reviewjs/annotate/examples/annotate-server.cjs ./annotate-server.cjs \
 && node patch.cjs annotate-server.cjs
COPY app.cjs ./app.cjs
ENV PORT=80 HOST=0.0.0.0 DATA_DIR=/data
EXPOSE 80
CMD ["node", "app.cjs"]
