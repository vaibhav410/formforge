import json

from fastapi.testclient import TestClient

from app.config import Settings, get_settings
from app.main import app, get_generator
from app.services.generator import FormGenerator
from app.services.groq_client import ChatResult

TOKEN = "test-token"

VALID_SCHEMA = {
    "schema_version": 1,
    "title": "Contact form",
    "description": None,
    "settings": {"submit_label": "Send", "success_message": "Thanks!"},
    "sections": [
        {
            "title": "Main",
            "description": None,
            "fields": [
                {
                    "key": "email", "type": "email", "label": "Email",
                    "required": True, "options": None,
                }
            ],
        }
    ],
}


class FakeGroqClient:
    """Replays scripted responses; used to test the repair loop offline."""

    def __init__(self, responses):
        self.responses = list(responses)
        self.calls = 0

    async def chat(self, messages, model, json_mode=True):
        self.calls += 1
        content = self.responses.pop(0)
        return ChatResult(
            content=content, model=model,
            prompt_tokens=100, completion_tokens=200, latency_ms=50,
        )


def make_settings(**overrides) -> Settings:
    return Settings(
        groq_api_key="fake", ai_service_token=TOKEN,
        max_repair_attempts=3, _env_file=None, **overrides,
    )


def make_client(responses) -> tuple[TestClient, FakeGroqClient]:
    fake = FakeGroqClient(responses)
    settings = make_settings()
    app.dependency_overrides[get_settings] = lambda: settings
    app.dependency_overrides[get_generator] = lambda: FormGenerator(settings, client=fake)
    return TestClient(app), fake


def teardown_function():
    app.dependency_overrides.clear()


def test_health_is_public():
    client, _ = make_client([])
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json()["provider"] == "groq"


def test_generate_requires_token():
    client, _ = make_client([])
    response = client.post("/v1/forms/generate", json={"prompt": "a contact form"})
    assert response.status_code == 401


def test_generate_happy_path():
    client, fake = make_client([json.dumps(VALID_SCHEMA)])
    response = client.post(
        "/v1/forms/generate",
        json={"prompt": "a contact form"},
        headers={"Authorization": f"Bearer {TOKEN}"},
    )
    assert response.status_code == 200
    body = response.json()
    assert body["schema"]["title"] == "Contact form"
    assert body["attempts"][0]["outcome"] == "success"
    assert fake.calls == 1


def test_repair_loop_recovers_from_invalid_schema():
    broken = dict(VALID_SCHEMA, sections=[])  # violates min 1 section
    client, fake = make_client([json.dumps(broken), json.dumps(VALID_SCHEMA)])
    response = client.post(
        "/v1/forms/generate",
        json={"prompt": "a contact form"},
        headers={"Authorization": f"Bearer {TOKEN}"},
    )
    assert response.status_code == 200
    body = response.json()
    assert fake.calls == 2
    assert [a["outcome"] for a in body["attempts"]] == ["schema_invalid", "success"]


def test_gives_up_after_max_attempts():
    client, fake = make_client(["not json at all"] * 3)
    response = client.post(
        "/v1/forms/generate",
        json={"prompt": "a contact form"},
        headers={"Authorization": f"Bearer {TOKEN}"},
    )
    assert response.status_code == 422
    assert fake.calls == 3
    assert len(response.json()["attempts"]) == 3


def test_edit_returns_updated_schema():
    edited = json.loads(json.dumps(VALID_SCHEMA))
    edited["sections"][0]["fields"].append(
        {"key": "phone", "type": "phone", "label": "Phone", "options": None}
    )
    client, _ = make_client([json.dumps(edited)])
    response = client.post(
        "/v1/forms/edit",
        json={"prompt": "add a phone field", "schema": VALID_SCHEMA},
        headers={"Authorization": f"Bearer {TOKEN}"},
    )
    assert response.status_code == 200
    keys = [f["key"] for f in response.json()["schema"]["sections"][0]["fields"]]
    assert keys == ["email", "phone"]
