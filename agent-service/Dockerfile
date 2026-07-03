FROM python:3.12-slim

WORKDIR /app

# Copy and install requirements first (leverages Docker layer caching)
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 8001
# Replace 'main:app' with 'agents:app' if your primary file is named agents.py
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001"]

