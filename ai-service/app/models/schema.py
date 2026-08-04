"""Pydantic mirror of the canonical form schema contract.

This is the AI-side twin of Laravel's app/Schema/FormSchemaValidator.
LLM output must parse into FormSchemaModel before it ever leaves this
service; Laravel then re-validates on receipt (defence in depth — the
two validators are kept in lockstep via docs/SCHEMA.md).
"""

from __future__ import annotations

import re
from enum import Enum
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

SCHEMA_VERSION = 1

KEY_PATTERN = re.compile(r"^[a-z][a-z0-9_]{0,63}$")


class FieldType(str, Enum):
    text = "text"
    textarea = "textarea"
    number = "number"
    email = "email"
    phone = "phone"
    date = "date"
    time = "time"
    dropdown = "dropdown"
    radio = "radio"
    checkbox = "checkbox"
    file = "file"
    rating = "rating"
    heading = "heading"
    address = "address"
    url = "url"
    password = "password"
    signature = "signature"
    color = "color"
    hidden = "hidden"


OPTION_TYPES = {FieldType.dropdown, FieldType.radio, FieldType.checkbox}


class OptionModel(BaseModel):
    model_config = ConfigDict(extra="ignore")

    label: str = Field(min_length=1, max_length=255)
    value: str = Field(min_length=1, max_length=255)


class ValidationModel(BaseModel):
    model_config = ConfigDict(extra="ignore")

    min: float | str | None = None
    max: float | str | None = None
    min_length: int | None = Field(default=None, ge=0)
    max_length: int | None = Field(default=None, ge=0)
    regex: str | None = None
    mimes: list[str] | None = None
    max_size_kb: int | None = Field(default=None, ge=1, le=51200)
    multiple: bool | None = None

    @field_validator("regex")
    @classmethod
    def regex_must_compile(cls, value: str | None) -> str | None:
        if value:
            try:
                re.compile(value)
            except re.error as exc:
                raise ValueError(f"regex does not compile: {exc}")
        return value


class ConditionModel(BaseModel):
    model_config = ConfigDict(extra="ignore")

    field: str
    operator: Literal[
        "equals", "not_equals", "contains",
        "greater_than", "less_than", "is_empty", "is_not_empty",
    ]
    value: str | int | float | bool | None = None


class LogicModel(BaseModel):
    model_config = ConfigDict(extra="ignore")

    action: Literal["show", "hide"] = "show"
    match: Literal["all", "any"] = "all"
    conditions: list[ConditionModel] = Field(min_length=1)


class FieldModel(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: str | None = None  # Laravel's sanitizer generates missing ids
    key: str
    type: FieldType
    label: str = Field(min_length=1, max_length=255)
    description: str | None = None
    placeholder: str | None = None
    required: bool = False
    default: Any = None
    options: list[OptionModel] | None = None
    validation: ValidationModel = Field(default_factory=ValidationModel)
    css_class: str | None = None
    hidden: bool = False
    logic: LogicModel | None = None
    meta: dict[str, Any] = Field(default_factory=dict)

    @field_validator("key")
    @classmethod
    def key_is_slug(cls, value: str) -> str:
        if not KEY_PATTERN.match(value):
            raise ValueError(
                f'key "{value}" must be snake_case (a-z, 0-9, _), starting with a letter'
            )
        return value

    @model_validator(mode="after")
    def options_match_type(self) -> "FieldModel":
        if self.type in OPTION_TYPES:
            if not self.options:
                raise ValueError(f'a "{self.type.value}" field needs at least one option')
            values = [option.value for option in self.options]
            if len(values) != len(set(values)):
                raise ValueError(f'field "{self.key}" has duplicate option values')
        return self


class SectionModel(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: str | None = None
    title: str = Field(min_length=1, max_length=255)
    description: str | None = None
    fields: list[FieldModel] = Field(default_factory=list)


class SettingsModel(BaseModel):
    model_config = ConfigDict(extra="ignore")

    submit_label: str = "Submit"
    success_message: str = "Thank you — your response has been recorded."


class FormSchemaModel(BaseModel):
    model_config = ConfigDict(extra="ignore")

    schema_version: int = SCHEMA_VERSION
    title: str = Field(min_length=1, max_length=255)
    description: str | None = None
    settings: SettingsModel = Field(default_factory=SettingsModel)
    sections: list[SectionModel] = Field(min_length=1)

    @model_validator(mode="after")
    def cross_field_checks(self) -> "FormSchemaModel":
        keys: list[str] = []
        for section in self.sections:
            for field in section.fields:
                keys.append(field.key)

        duplicates = {key for key in keys if keys.count(key) > 1}
        if duplicates:
            raise ValueError(f"duplicate field keys: {', '.join(sorted(duplicates))}")

        key_set = set(keys)
        for section in self.sections:
            for field in section.fields:
                if field.logic is None:
                    continue
                for condition in field.logic.conditions:
                    if condition.field not in key_set:
                        raise ValueError(
                            f'field "{field.key}" logic references unknown field '
                            f'"{condition.field}"'
                        )
                    if condition.field == field.key:
                        raise ValueError(f'field "{field.key}" logic references itself')

        self.schema_version = SCHEMA_VERSION
        return self
