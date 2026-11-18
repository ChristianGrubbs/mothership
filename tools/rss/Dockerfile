FROM python:3.13-alpine
RUN addgroup -S app && adduser -S -G app app
WORKDIR /app
COPY server.py announcements.py trending.py /app/
RUN mkdir /state && chown app:app /state
USER app
EXPOSE 8788
HEALTHCHECK --interval=30s --timeout=3s --retries=3 CMD wget -qO- http://127.0.0.1:8788/health || exit 1
CMD ["python", "-u", "/app/server.py"]
