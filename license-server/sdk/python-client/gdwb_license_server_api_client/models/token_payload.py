from __future__ import annotations

from collections.abc import Mapping
from typing import Any, TypeVar, BinaryIO, TextIO, TYPE_CHECKING, Generator

from attrs import define as _attrs_define
from attrs import field as _attrs_field

from ..types import UNSET, Unset

from ..types import UNSET, Unset
from typing import cast






T = TypeVar("T", bound="TokenPayload")



@_attrs_define
class TokenPayload:
    """ 
        Attributes:
            iss (str):
            sub (str):
            aud (str):
            iat (int):
            exp (int):
            jti (str):
            scope (list[str] | Unset):
            features (list[str] | Unset):
            site (str | Unset):
     """

    iss: str
    sub: str
    aud: str
    iat: int
    exp: int
    jti: str
    scope: list[str] | Unset = UNSET
    features: list[str] | Unset = UNSET
    site: str | Unset = UNSET
    additional_properties: dict[str, Any] = _attrs_field(init=False, factory=dict)





    def to_dict(self) -> dict[str, Any]:
        iss = self.iss

        sub = self.sub

        aud = self.aud

        iat = self.iat

        exp = self.exp

        jti = self.jti

        scope: list[str] | Unset = UNSET
        if not isinstance(self.scope, Unset):
            scope = self.scope



        features: list[str] | Unset = UNSET
        if not isinstance(self.features, Unset):
            features = self.features



        site = self.site


        field_dict: dict[str, Any] = {}
        field_dict.update(self.additional_properties)
        field_dict.update({
            "iss": iss,
            "sub": sub,
            "aud": aud,
            "iat": iat,
            "exp": exp,
            "jti": jti,
        })
        if scope is not UNSET:
            field_dict["scope"] = scope
        if features is not UNSET:
            field_dict["features"] = features
        if site is not UNSET:
            field_dict["site"] = site

        return field_dict



    @classmethod
    def from_dict(cls: type[T], src_dict: Mapping[str, Any]) -> T:
        d = dict(src_dict)
        iss = d.pop("iss")

        sub = d.pop("sub")

        aud = d.pop("aud")

        iat = d.pop("iat")

        exp = d.pop("exp")

        jti = d.pop("jti")

        scope = cast(list[str], d.pop("scope", UNSET))


        features = cast(list[str], d.pop("features", UNSET))


        site = d.pop("site", UNSET)

        token_payload = cls(
            iss=iss,
            sub=sub,
            aud=aud,
            iat=iat,
            exp=exp,
            jti=jti,
            scope=scope,
            features=features,
            site=site,
        )


        token_payload.additional_properties = d
        return token_payload

    @property
    def additional_keys(self) -> list[str]:
        return list(self.additional_properties.keys())

    def __getitem__(self, key: str) -> Any:
        return self.additional_properties[key]

    def __setitem__(self, key: str, value: Any) -> None:
        self.additional_properties[key] = value

    def __delitem__(self, key: str) -> None:
        del self.additional_properties[key]

    def __contains__(self, key: str) -> bool:
        return key in self.additional_properties
