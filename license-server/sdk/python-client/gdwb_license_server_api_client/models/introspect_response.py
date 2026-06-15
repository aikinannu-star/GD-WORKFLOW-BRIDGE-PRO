from __future__ import annotations

from collections.abc import Mapping
from typing import Any, TypeVar, BinaryIO, TextIO, TYPE_CHECKING, Generator

from attrs import define as _attrs_define
from attrs import field as _attrs_field

from ..types import UNSET, Unset

from typing import cast

if TYPE_CHECKING:
  from ..models.token_payload import TokenPayload





T = TypeVar("T", bound="IntrospectResponse")



@_attrs_define
class IntrospectResponse:
    """ 
        Attributes:
            success (bool):
            payload (TokenPayload):
     """

    success: bool
    payload: TokenPayload
    additional_properties: dict[str, Any] = _attrs_field(init=False, factory=dict)





    def to_dict(self) -> dict[str, Any]:
        from ..models.token_payload import TokenPayload
        success = self.success

        payload = self.payload.to_dict()


        field_dict: dict[str, Any] = {}
        field_dict.update(self.additional_properties)
        field_dict.update({
            "success": success,
            "payload": payload,
        })

        return field_dict



    @classmethod
    def from_dict(cls: type[T], src_dict: Mapping[str, Any]) -> T:
        from ..models.token_payload import TokenPayload
        d = dict(src_dict)
        success = d.pop("success")

        payload = TokenPayload.from_dict(d.pop("payload"))




        introspect_response = cls(
            success=success,
            payload=payload,
        )


        introspect_response.additional_properties = d
        return introspect_response

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
