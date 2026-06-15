from __future__ import annotations

from collections.abc import Mapping
from typing import Any, TypeVar, BinaryIO, TextIO, TYPE_CHECKING, Generator

from attrs import define as _attrs_define
from attrs import field as _attrs_field

from ..types import UNSET, Unset

from ..types import UNSET, Unset






T = TypeVar("T", bound="PostApiV1AdminTokenRevokeBody")



@_attrs_define
class PostApiV1AdminTokenRevokeBody:
    """ 
        Attributes:
            jti (str):
            token (str | Unset):
     """

    jti: str
    token: str | Unset = UNSET
    additional_properties: dict[str, Any] = _attrs_field(init=False, factory=dict)





    def to_dict(self) -> dict[str, Any]:
        jti = self.jti

        token = self.token


        field_dict: dict[str, Any] = {}
        field_dict.update(self.additional_properties)
        field_dict.update({
            "jti": jti,
        })
        if token is not UNSET:
            field_dict["token"] = token

        return field_dict



    @classmethod
    def from_dict(cls: type[T], src_dict: Mapping[str, Any]) -> T:
        d = dict(src_dict)
        jti = d.pop("jti")

        token = d.pop("token", UNSET)

        post_api_v1_admin_token_revoke_body = cls(
            jti=jti,
            token=token,
        )


        post_api_v1_admin_token_revoke_body.additional_properties = d
        return post_api_v1_admin_token_revoke_body

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
