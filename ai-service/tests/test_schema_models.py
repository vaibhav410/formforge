import pytest
from pydantic import ValidationError

from app.models.schema import FormSchemaModel


def minimal_schema(**overrides):
    schema = {
        "title": "Test form",
        "sections": [
            {
                "title": "Main",
                "fields": [
                    {"key": "name", "type": "text", "label": "Name"},
                    {
                        "key": "color",
                        "type": "dropdown",
                        "label": "Colour",
                        "options": [
                            {"label": "Red", "value": "red"},
                            {"label": "Blue", "value": "blue"},
                        ],
                    },
                ],
            }
        ],
    }
    schema.update(overrides)
    return schema


def test_valid_schema_passes():
    model = FormSchemaModel.model_validate(minimal_schema())
    assert model.schema_version == 1
    assert model.sections[0].fields[1].options[0].value == "red"


def test_unknown_field_type_rejected():
    schema = minimal_schema()
    schema["sections"][0]["fields"][0]["type"] = "multiselect"
    with pytest.raises(ValidationError):
        FormSchemaModel.model_validate(schema)


def test_duplicate_keys_rejected():
    schema = minimal_schema()
    schema["sections"][0]["fields"][1]["key"] = "name"
    with pytest.raises(ValidationError, match="duplicate field keys"):
        FormSchemaModel.model_validate(schema)


def test_choice_without_options_rejected():
    schema = minimal_schema()
    schema["sections"][0]["fields"][1]["options"] = []
    with pytest.raises(ValidationError):
        FormSchemaModel.model_validate(schema)


def test_bad_key_format_rejected():
    schema = minimal_schema()
    schema["sections"][0]["fields"][0]["key"] = "Full Name"
    with pytest.raises(ValidationError):
        FormSchemaModel.model_validate(schema)


def test_logic_referencing_unknown_field_rejected():
    schema = minimal_schema()
    schema["sections"][0]["fields"][0]["logic"] = {
        "action": "show",
        "match": "all",
        "conditions": [{"field": "ghost", "operator": "equals", "value": "x"}],
    }
    with pytest.raises(ValidationError, match="unknown field"):
        FormSchemaModel.model_validate(schema)


def test_self_referencing_logic_rejected():
    schema = minimal_schema()
    schema["sections"][0]["fields"][0]["logic"] = {
        "action": "show",
        "match": "all",
        "conditions": [{"field": "name", "operator": "is_empty", "value": None}],
    }
    with pytest.raises(ValidationError, match="references itself"):
        FormSchemaModel.model_validate(schema)


def test_invalid_regex_rejected():
    schema = minimal_schema()
    schema["sections"][0]["fields"][0]["validation"] = {"regex": "([a-z"}
    with pytest.raises(ValidationError):
        FormSchemaModel.model_validate(schema)


def test_extra_hallucinated_properties_are_ignored():
    schema = minimal_schema()
    schema["sections"][0]["fields"][0]["autocomplete"] = "on"
    schema["theme"] = "dark"
    model = FormSchemaModel.model_validate(schema)
    assert not hasattr(model, "theme")
