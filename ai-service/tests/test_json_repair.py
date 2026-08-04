import pytest

from app.services.json_repair import extract_json


def test_plain_json():
    assert extract_json('{"a": 1}') == {"a": 1}


def test_markdown_fence():
    text = 'Here is your form:\n```json\n{"title": "X"}\n```\nHope that helps!'
    assert extract_json(text) == {"title": "X"}


def test_leading_and_trailing_prose():
    text = 'Sure! {"title": "Form", "sections": []} Let me know if you need changes.'
    assert extract_json(text) == {"title": "Form", "sections": []}


def test_trailing_commas():
    text = '{"title": "X", "sections": [{"fields": [],},],}'
    assert extract_json(text) == {"title": "X", "sections": [{"fields": []}]}


def test_truncated_output_gets_closed():
    text = '{"title": "X", "sections": [{"title": "S", "fields": ['
    parsed = extract_json(text)
    assert parsed["title"] == "X"
    assert parsed["sections"][0]["fields"] == []


def test_braces_inside_strings_do_not_confuse_slicing():
    text = '{"title": "a } b", "n": 1} trailing'
    assert extract_json(text) == {"title": "a } b", "n": 1}


def test_no_json_raises():
    with pytest.raises(ValueError):
        extract_json("I cannot help with that.")
