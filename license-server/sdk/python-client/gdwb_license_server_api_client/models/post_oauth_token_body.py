from __future__ import annotations

from collections.abc import Mapping
from typing import Any, TypeVar, BinaryIO, TextIO, TYPE_CHECKING, Generator

from attrs import define as _attrs_define
from attrs import field as _attrs_field

from ..types import UNSET, Unset

from ..models.post_oauth_token_body_grant_type import PostOauthTokenBodyGrantType
from ..types import UNSET, Unset






T = TypeVar("T", bound="PostOauthTokenBody")



@_attrs_define
class PostOauthTokenBody:
    """ 
        Attributes:
            grant_type (PostOauthTokenBodyGrantType):
            client_id (str | Unset):
            client_secret (str | Unset):
            license_key (str | Unset):
            site (str | Unset):
     """

    grant_type: PostOauthTokenBodyGrantType
    client_id: str | Unset = UNSET
    client_secret: str | Unset = UNSET
    license_key: str | Unset = UNSET
    site: str | Unset = UNSET
    additional_properties: dict[str, Any] = _attrs_field(init=False, factory=dict)





    def to_dict(self) -> dict[str, Any]:
        grant_type = self.grant_type.value

        client_id = self.client_id

        client_secret = self.client_secret

        license_key = self.license_key

        site = self.site


        field_dict: dict[str, Any] = {}
        field_dict.update(self.additional_properties)
        field_dict.update({
            "grant_type": grant_type,
        })
        if client_id is not UNSET:
            field_dict["client_id"] = client_id
        if client_secret is not UNSET:
            field_dict["client_secret"] = client_secret
        if license_key is not UNSET:
            field_dict["license_key"] = license_key
        if site is not UNSET:
            field_dict["site"] = site

        return field_dict



    @classmethod
    def from_dict(cls: type[T], src_dict: Mapping[str, Any]) -> T:
        d = dict(src_dict)
        grant_type = PostOauthTokenBodyGrantType(d.pop("grant_type"))




        client_id = d.pop("client_id", UNSET)

        client_secret = d.pop("client_secret", UNSET)

        license_key = d.pop("license_key", UNSET)

        site = d.pop("site", UNSET)

        post_oauth_token_body = cls(
            grant_type=grant_type,
            client_id=client_id,
            client_secret=client_secret,
            license_key=license_key,
            site=site,
        )


        post_oauth_token_body.additional_properties = d
        return post_oauth_token_body

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
